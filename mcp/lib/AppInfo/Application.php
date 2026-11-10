<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
declare(strict_types=1);

namespace OCA\Mcp\AppInfo;

use OCA\Mcp\Access\Grants\GrantPolicy;
use OCA\Mcp\Mcp\Protocol\McpProtocol;
use OCA\Mcp\Mcp\Prompts\PromptCatalog;
use OCA\Mcp\Mcp\Resources\ResourceRegistry;
use OCA\Mcp\Platform\Time\UserTimezone;
use OCA\Mcp\Access\Visibility\VisibilityGuard;
use OCA\Mcp\Modules\Calendar\CalendarModule;
use OCA\Mcp\Modules\Deck\DeckModule;
use OCA\Mcp\Modules\Deck\CommentAccess;
use OCA\Mcp\Modules\Deck\CommentAudience;
use OCA\Mcp\Modules\Deck\CommentOperations;
use OCA\Mcp\Modules\Deck\NativeCommentAccess;
use OCA\Mcp\Modules\Deck\Recipients\DeckRecipientDirectory;
use OCA\Mcp\Modules\Talk\TalkModule;
use OCA\Mcp\Platform\Dav\CalDav\ObjectStore as CalendarStore;
use OCA\Mcp\Platform\Dav\CalDav\CalDavWriter as CalendarDav;
use OCA\Mcp\Modules\Calendar\Selftest\CalendarSelftestReader;
use OCA\Mcp\Modules\Calendar\Integration\DavCalendarSelftestReader;
use OCA\Mcp\Platform\Dav\CalDav\DavReadStore as DavCalendarStore;
use OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavServerFactory;
use OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher as EmbeddedDavDispatcher;
use OCA\Mcp\Platform\Dav\Transport\Session;
use OCA\Mcp\Modules\Files\FilesModule;
use OCA\Mcp\Modules\Files\Reading\TextExtractor;
use OCA\Mcp\Modules\Notes\NotesModule;
use OCA\Mcp\Modules\Notes\Storage\NotesFolders;
use OCA\Mcp\Mcp\Tools\ToolRegistry;
use OCP\App\IAppManager;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\ITempManager;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/** App bootstrap: loads the bundled production vendor/ and wires the tool registry with its explicit module list. */
class Application extends App implements IBootstrap {
    /** App id, equal to the app directory name. */
    public const APP_ID = 'mcp';

    /** Tool modules in tools/list order; registered explicitly, no autodiscovery. */
    public const MODULES = [
        FilesModule::class,
        NotesModule::class,
        CalendarModule::class,
        \OCA\Mcp\Modules\Contacts\ContactsModule::class,
        \OCA\Mcp\Modules\Tasks\TasksModule::class,
        DeckModule::class,
        TalkModule::class,
        \OCA\Mcp\Modules\People\PeopleModule::class,
        \OCA\Mcp\Modules\Logs\LogsModule::class,
        \OCA\Mcp\Modules\Mail\MailModule::class,
        \OCA\Mcp\Modules\Forms\FormsModule::class,
    ];

    public function __construct() {
        parent::__construct(self::APP_ID);
    }

    /** @param IRegistrationContext $context registration context of the app */
    public function register(IRegistrationContext $context): void {
        $context->registerService(\OCA\Mcp\Access\FileWritePolicy\FileWritePolicy::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Access\FileWritePolicy\FileWritePolicy => new \OCA\Mcp\Access\FileWritePolicy\FileWritePolicy(
                $c->get(\OCP\IAppConfig::class), $c->get(\OCP\IDBConnection::class),
            ));
        $context->registerService(\OCA\Mcp\Platform\FileAccess\Policy\FileTypeGuard::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Platform\FileAccess\Policy\FileTypeGuard => new \OCA\Mcp\Platform\FileAccess\Policy\FileTypeGuard(
                $c->get(\OCA\Mcp\Access\FileWritePolicy\FileWritePolicy::class),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Creation\FileCreationLockGuard::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Creation\FileCreationLockGuard => new \OCA\Mcp\Modules\Files\Creation\FileCreationLockGuard(
                $c->get(IConfig::class),
            ));
        $context->registerService(\OCA\Mcp\Access\Guests\GuestRestrictions::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Access\Guests\GuestRestrictions(
            $c->get(IAppManager::class), $c->get(\OCP\IRequest::class),
            static fn () => $c->get('OCA\\Guests\\GuestManager'),
            static fn () => $c->get('OCA\\Guests\\AppWhitelist'),
            static fn () => $c->get('OCA\\Guests\\RestrictionManager'),
            static fn () => $c->get('OCA\\Guests\\Config'),
            $c->get(LoggerInterface::class),
        ));
        $context->registerService(\OCA\Mcp\Access\Policy\Transactions\PolicyTransaction::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Access\Policy\Transactions\PolicyTransaction(
            $c->get(\OCP\IDBConnection::class), $c->get(\OCA\Mcp\Db\PolicyStateMapper::class),
            $c->get(\OCA\Mcp\Db\PolicyEventsMapper::class), $c->get(\OCP\Config\IUserConfig::class),
            $c->get(\OCP\IAppConfig::class), $c->get(\OCA\Mcp\Access\Policy\Events\PolicyEventProcessor::class),
        ));
        $context->registerEventListener('OCA\\Files\\Event\\LoadAdditionalScriptsEvent', \OCA\Mcp\Listener\FilesDescriptionSidebarListener::class);
        $context->registerEventListener(\OCP\User\Events\UserChangedEvent::class, \OCA\Mcp\OAuth\UserRevocationListener::class);
        // A reused UID starts without the previous account's connection-level tool choices, even if the old account's
        // UserDeletedEvent was missed and the paired deletion listener therefore could not clear them safely.
        $context->registerEventListener(\OCP\User\Events\BeforeUserCreatedEvent::class, \OCA\Mcp\Listener\ConnectionToolsCreationListener::class);
        // Recheck causes of account deletion and group removal (contracts §3.3.2); the deletion's cut comes from its
        // account cause, so a late UserDeletedEvent never cuts a consent made after the cause was consumed.
        foreach ([\OCP\User\Events\BeforeUserDeletedEvent::class, \OCP\User\Events\UserDeletedEvent::class,
            \OCP\Group\Events\BeforeUserRemovedEvent::class, \OCP\Group\Events\UserRemovedEvent::class,
            \OCP\Group\Events\BeforeUserAddedEvent::class, \OCP\Group\Events\UserAddedEvent::class] as $event) {
            $context->registerEventListener($event, \OCA\Mcp\Listener\UserCauseListener::class);
        }
        // The GID lifecycle (contracts §3.3.5): pending_delete, purge by gen and by the revision read at creation.
        foreach ([\OCP\Group\Events\BeforeGroupDeletedEvent::class, \OCP\Group\Events\GroupDeletedEvent::class,
            \OCP\Group\Events\BeforeGroupCreatedEvent::class, \OCP\Group\Events\GroupCreatedEvent::class] as $event) {
            $context->registerEventListener($event, \OCA\Mcp\Listener\GroupLifecycleListener::class);
        }
        $context->registerMiddleware(\OCA\Mcp\Middleware\DeferredFlushMiddleware::class);
        $context->registerService(\OCA\Mcp\Access\ResourceScope\ScopeReader::class, static fn (ContainerInterface $c): \OCA\Mcp\Access\ResourceScope\ScopeReader => $c->get(\OCA\Mcp\Access\Grants\GrantPolicy::class));
        // Production dependencies (smalot/pdfparser) are bundled in the package's vendor/.
        $autoload = __DIR__ . '/../../vendor/autoload.php';
        if (is_file($autoload)) {
            require_once $autoload;
        }
        $context->registerService(\OCA\Mcp\Modules\Talk\Access\ConversationResolver::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Access\ConversationResolver(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Manager', static fn () => $c->get('OCA\\Talk\\Manager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\RoomService', static fn () => $c->get('OCA\\Talk\\Service\\RoomService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Reading\ConversationReader((new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Manager', static fn () => $c->get('OCA\\Talk\\Manager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\CommentsManager', static fn () => $c->get('OCA\\Talk\\Chat\\CommentsManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Access\ConversationResolver::class),
            $c->get(\OCA\Mcp\Modules\Talk\Presentation\ActorNames::class),
            $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)));
        $context->registerService(\OCA\Mcp\Modules\Calendar\Contracts\EventReferenceReader::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Modules\Calendar\References\CalendarEventReferenceReader::class));
        $context->registerService(\OCA\Mcp\Modules\Talk\Tools\ListConversations::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Tools\ListConversations(
            $c->get(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class)->listConversations(...),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Tools\ReadMessages::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Tools\ReadMessages(
            $c->get(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class)->readMessagePage(...),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Tools\SearchMessages::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Tools\SearchMessages(
            $c->get(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class)->searchMessages(...),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Tools\ReadMessageContext::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Tools\ReadMessageContext(
            $c->get(\OCA\Mcp\Modules\Talk\Reading\ConversationReader::class)->readMessageContext(...),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Integration\TalkAvailability(
            $c->get(\OCP\App\IAppManager::class),
            $c->get(\OCP\IUserManager::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Messaging\ReplyTargetReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Messaging\ReplyTargetReader(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Messaging\MessageSender::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Messaging\MessageSender(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $c->get(\OCA\Mcp\Modules\Talk\Messaging\ReplyTargetReader::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Attachments\AttachmentPublisher::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Attachments\AttachmentPublisher(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Chat\\ChatManager', static fn () => $c->get('OCA\\Talk\\Chat\\ChatManager'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $c->get(\OCA\Mcp\Modules\Talk\Attachments\AttachmentMessage::class),
            $c->get(\OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Attachments\UserFileResolver::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Attachments\UserFileResolver($c->get(\OCP\Files\IRootFolder::class),
            $c->get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class),
            $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)));
        $context->registerService(\OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Attachments\AttachmentAccess($c->get(\OCP\Share\IManager::class),
            $c->get(\OCA\Mcp\Access\Visibility\VisibilityGuard::class),
            $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)));
        $context->registerService(\OCA\Mcp\Modules\Talk\DirectMessaging\OneToOneConversation::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\DirectMessaging\OneToOneConversation(
            $c->get(\OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver::class),
            $c->get(\OCP\IUserManager::class),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\RoomService', static fn () => $c->get('OCA\\Talk\\Service\\RoomService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Groups\GroupCreation::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Groups\GroupCreation(
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\RoomService', static fn () => $c->get('OCA\\Talk\\Service\\RoomService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
            $c->get(\OCP\IUserManager::class),
            $c->get(\OCA\Mcp\Modules\Talk\DirectMessaging\ContactResolver::class),
            $c->get(\Psr\Log\LoggerInterface::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\Messaging\SummaryPublishing::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\Messaging\SummaryPublishing(
            $c->get(\OCA\Mcp\Modules\Talk\Messaging\MessageSender::class),
            $c->get(\OCA\Mcp\Modules\Talk\Access\ConversationResolver::class),
            $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class),
            (new \OCA\Mcp\Modules\Talk\Integration\TalkServiceFactory('OCA\\Talk\\Service\\ParticipantService', static fn () => $c->get('OCA\\Talk\\Service\\ParticipantService'), $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkAvailability::class)))->forUser(...),
            $c->get(\OCA\Mcp\Modules\Talk\Integration\TalkConstants::class)->forUser(...),
        ));
        $context->registerService(\OCA\Mcp\Modules\Talk\TalkModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Talk\TalkModule(
            [
                \OCA\Mcp\Modules\Talk\Tools\ListConversations::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\ListConversations::class),
                \OCA\Mcp\Modules\Talk\Tools\ReadMessages::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\ReadMessages::class),
                \OCA\Mcp\Modules\Talk\Tools\SearchMessages::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\SearchMessages::class),
                \OCA\Mcp\Modules\Talk\Tools\ReadMessageContext::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\ReadMessageContext::class),
                \OCA\Mcp\Modules\Talk\Tools\Reply::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\Reply::class),
                \OCA\Mcp\Modules\Talk\Tools\AttachFile::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\AttachFile::class),
                \OCA\Mcp\Modules\Talk\Tools\QuoteFile::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\QuoteFile::class),
                \OCA\Mcp\Modules\Talk\Tools\MessageUser::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\MessageUser::class),
                \OCA\Mcp\Modules\Talk\Tools\SendBatch::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\SendBatch::class),
                \OCA\Mcp\Modules\Talk\Tools\PublishSummary::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\PublishSummary::class),
                \OCA\Mcp\Modules\Talk\Tools\CreateGroup::NAME => static fn () => $c->get(\OCA\Mcp\Modules\Talk\Tools\CreateGroup::class),
            ],
            $c->get(\OCA\Mcp\Modules\Talk\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Talk\Execution\PreviewBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\CollectionStore::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Platform\Dav\CalDav\DavReadStore::class));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\CalendarAdministration::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher::class));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\CalendarAclProbe::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Platform\Dav\CalDav\EmbeddedCalDavDispatcher::class));
        $context->registerService(\OCA\Mcp\Modules\Calendar\Reading\EventRangeQuery::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Calendar\Integration\DavEventRangeQuery(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class)));
        $context->registerService(\OCA\Mcp\Platform\Dav\CalDav\DavReadStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Platform\Dav\CalDav\DavReadStore(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class), static fn () => $c->get(\OCP\IDBConnection::class)));
        $context->registerService(\OCA\Mcp\Modules\Contacts\ContactsModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Contacts\ContactsModule([
            'contacts_list_addressbooks' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\ListAddressBooks::class),
            'contacts_search_contacts' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\SearchContacts::class),
            'contacts_read_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\ReadContact::class),
            'contacts_create_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\CreateContact::class),
            'contacts_edit_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\EditContact::class),
            'contacts_delete_contact' => static fn () => $c->get(\OCA\Mcp\Modules\Contacts\Tools\DeleteContact::class),
        ]));
        $context->registerService(\OCA\Mcp\Modules\Tasks\TasksModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Tasks\TasksModule([
            'tasks_list_calendars' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\ListCalendars::class),
            'tasks_list_tasks' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\ListTasks::class),
            'tasks_read_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\ReadTask::class),
            'tasks_create_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\CreateTask::class),
            'tasks_edit_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\EditTask::class),
            'tasks_complete_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\CompleteTask::class),
            'tasks_delete_task' => static fn () => $c->get(\OCA\Mcp\Modules\Tasks\Tools\DeleteTask::class),
        ], static fn (string $uid) => $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)->assertAvailable($uid)));
        $context->registerService(CalendarModule::class, static fn (ContainerInterface $c) => new CalendarModule([
            'calendar_list_calendars' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\ListCalendars::class),
            'calendar_check_availability' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\CheckAvailability::class),
            'calendar_list_events' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\ListEvents::class),
            'calendar_create_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\CreateEvent::class),
            'calendar_update_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\UpdateEvent::class),
            'calendar_move_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\MoveEvent::class),
            'calendar_delete_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\DeleteEvent::class),
            'calendar_transfer_event' => static fn () => $c->get(\OCA\Mcp\Modules\Calendar\Tools\TransferEvent::class),
        ], static fn (string $uid) => $c->get(\OCA\Mcp\Platform\FileAccess\References\OutputReferenceFilter::class)->assertAvailable($uid)));
        $context->registerService(CalendarSelftestReader::class, static fn (ContainerInterface $c): CalendarSelftestReader => new DavCalendarSelftestReader(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class)));
        $context->registerService(CalendarDav::class, static fn (ContainerInterface $c): CalendarDav => $c->get(EmbeddedDavDispatcher::class));
        $context->registerService(\OCA\Mcp\Modules\Contacts\AddressBooks\ContactStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Contacts\Integration\DavContactStore(static fn () => $c->get(\OCA\DAV\CardDAV\CardDavBackend::class)));
        $context->registerService(\OCA\Mcp\Modules\Tasks\Reading\TaskQuery::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Tasks\Integration\DavTaskQuery(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class)));
        $context->registerService(\OCA\Mcp\Platform\Dav\CardDav\ContactDav::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Platform\Dav\CardDav\EmbeddedCardDavDispatcher(
            static fn (): \Sabre\DAV\Server => (new \OCA\Mcp\Platform\Dav\CardDav\EmbeddedCardDavServer(
                static fn () => $c->get(\OCP\IURLGenerator::class),
                static fn () => $c->get(\Psr\Log\LoggerInterface::class),
                static fn () => $c->get(\OCP\IConfig::class),
                static fn () => $c->get(\OCP\L10N\IFactory::class),
                static fn () => $c->get(\OCP\EventDispatcher\IEventDispatcher::class),
                static fn () => $c->get(\OCA\DAV\CardDAV\PhotoCache::class),
                static fn () => $c->get(\OCA\DAV\CardDAV\Security\CardDavRateLimitingPlugin::class),
                static fn () => $c->get(\OCA\DAV\CardDAV\Validation\CardDavValidatePlugin::class),
                static fn () => new \OCA\DAV\AppInfo\PluginManager($c->get(\OCP\IServerContainer::class), $c->get(\OCP\App\IAppManager::class)),
            ))->create(),
            new Session($c->get(IUserSession::class)), $c->get(\OCP\IAppConfig::class), $c->get(LoggerInterface::class),
        ));
        // DavCalendarStore resolves CalDavBackend lazily (the app container falls back to the server one) on first use.
        $context->registerService(CalendarStore::class, static fn (ContainerInterface $c): CalendarStore => new DavCalendarStore(static fn () => $c->get(\OCA\DAV\CalDAV\CalDavBackend::class), static fn () => $c->get(\OCP\IDBConnection::class)));
        // The dispatcher builds one embedded CalDAV server per operation, so building the module never
        // loads the DAV app; a DAV app that fails to load only breaks the calendar writes.
        $context->registerService(EmbeddedDavDispatcher::class, static fn (ContainerInterface $c): EmbeddedDavDispatcher => new EmbeddedDavDispatcher(
            // The non-public embedded server, the one that takes a principal from CustomPrincipalPlugin instead of
            // answering with principals/system/public; the factory knows the Nextcloud 31 class as well.
            static fn (): \Sabre\DAV\Server => (new EmbeddedCalDavServerFactory(static fn () => $c->get(\OCP\IAppConfig::class), static fn () => $c->get(EmbeddedCalDavServerFactory::IMIP_PLUGIN)))->create(),
            new Session($c->get(IUserSession::class)),
            $c->get(IConfig::class),
            $c->get(LoggerInterface::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Access\DeckCaller(
            static fn () => $c->get(\OCA\Deck\Service\BoardService::class),
            $c->get(\OCP\ISession::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Contracts\CardReferenceReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\DeckCardReferenceReader($c->get(\OCA\Mcp\Modules\Deck\Reading\CardReader::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCP\IUserManager::class),
            static fn () => $c->get(\OCA\Deck\Service\AssignmentService::class),
            static fn () => $c->get(\OCA\Deck\Db\AssignmentMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\BoardReader($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\StackReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\StackReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\StackService::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\CardReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\CardReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Service\CardService::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\CardSearchReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Reading\CardSearchReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class),
            $c->get(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\LabelMapper::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            $c->get(UserTimezone::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Followup\FollowupQuery::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Followup\FollowupQuery(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class),
            $c->get(\OCA\Mcp\Modules\Deck\Assignments\AssignmentAccess::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            new UserTimezone($c->get(IConfig::class)),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Cards\CardWriter::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Cards\CardWriter(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\CardService::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            new UserTimezone($c->get(IConfig::class)),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Cards\CardStateReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Cards\CardStateReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Access\OwnershipReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Access\OwnershipReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn () => $c->get(\OCA\Deck\Service\BoardService::class),
            static fn () => $c->get(\OCP\IUserManager::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\BoardCreator::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\BoardCreator($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\StackCreator::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\StackCreator(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\StackService::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\StructureStateReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\StructureStateReader(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\BoardMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\StructureCardCounter::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\StructureCardCounter(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Access\OwnershipReader::class),
            static fn () => $c->get(\OCA\Deck\Service\PermissionService::class),
            static fn () => $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Structure\EmptyStructureDeletion(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            $c->get(\OCA\Mcp\Modules\Deck\Structure\StructureCardCounter::class),
            $c->get(\OCA\Mcp\Modules\Deck\Structure\StructureStateReader::class),
            static fn () => $c->get(\OCA\Deck\Service\StackService::class),
            static fn () => $c->get(\OCA\Deck\Service\BoardService::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class), static fn()=> $c->get(\OCA\Deck\Db\CardMapper::class), static fn()=> $c->get(\OCA\Deck\Service\PermissionService::class), static fn()=> $c->get(\OCA\Deck\Db\StackMapper::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Labels\NativeLabels::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Labels\NativeLabels($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class), static fn()=> $c->get(\OCA\Deck\Db\CardMapper::class), static fn()=> $c->get(\OCA\Deck\Service\CardService::class), static fn()=> $c->get(\OCA\Deck\Db\LabelMapper::class), static fn()=> $c->get(\OCA\Deck\Service\PermissionService::class), static fn()=> $c->get(\OCA\Deck\Db\StackMapper::class), $c->get(\OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter::class), $c->get(\OCP\IUserManager::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Files\NativeCardFiles::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Files\NativeCardFiles($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class), static fn()=> $c->get(\OCA\Deck\Service\AttachmentService::class), static fn()=> $c->get(\OCA\Deck\Db\CardMapper::class), static fn()=> $c->get(\OCA\Deck\Sharing\DeckShareProvider::class), static fn()=> $c->get(\OCA\Deck\Service\PermissionService::class), $c->get(\OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter::class), $c->get(\OCP\IUserManager::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Dependencies\NativeDependencies::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Dependencies\NativeDependencies($c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class), static fn()=> $c->get(\OCA\Deck\Db\CardMapper::class), static fn()=> $c->get(\OCA\Deck\Service\CardService::class), static fn()=> $c->get(\OCA\Deck\Service\PermissionService::class), $c->get(\OCA\Mcp\Modules\Deck\Reading\CardReferenceFilter::class), $c->get(\OCP\IUserManager::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Labels\LabelsAccess::class, static fn(ContainerInterface $c)=> $c->get(\OCA\Mcp\Modules\Deck\Labels\NativeLabels::class));
        $context->registerService(\OCA\Mcp\Modules\Deck\Files\CardFileAccess::class, static fn(ContainerInterface $c)=> $c->get(\OCA\Mcp\Modules\Deck\Files\NativeCardFiles::class));
        $context->registerService(\OCA\Mcp\Modules\Deck\Dependencies\DependencyAccess::class, static fn(ContainerInterface $c)=> $c->get(\OCA\Mcp\Modules\Deck\Dependencies\NativeDependencies::class));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListLabels::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Tools\ListLabels(new \OCA\Mcp\Modules\Deck\Labels\LabelListing($c->get(\OCA\Mcp\Modules\Deck\Labels\LabelsAccess::class), $c->get(LoggerInterface::class))));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\EditCardLabels::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Tools\EditCardLabels(new \OCA\Mcp\Modules\Deck\Labels\LabelEditing($c->get(\OCA\Mcp\Modules\Deck\Labels\LabelsAccess::class), $c->get(LoggerInterface::class), $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class), static fn()=> $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class)), $c->get(\OCA\Mcp\Modules\Deck\Execution\PreviewBoundary::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Files\Linking\NativeLinkSource::class, static fn(ContainerInterface $c)=>new \OCA\Mcp\Modules\Deck\Files\Linking\NativeLinkSource(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class), static fn()=> $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn()=> $c->get(\OCA\Deck\Db\BoardMapper::class), static fn()=> $c->get(\OCA\Deck\Db\StackMapper::class),
            static fn()=> $c->get(\OCA\Deck\Service\PermissionService::class), $c->get(\OCP\IUserManager::class), static function () use ($c):void {
                if (class_exists(\OCA\Circles\CirclesManager::class, false)) { $c->get(\OCA\Circles\CirclesManager::class)->stopSession(); }
            }));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\LinkCardFile::class, static function (ContainerInterface $c) {
            $gateway = $c->get(\OCA\Mcp\Modules\Deck\Files\Linking\NativeLinkSource::class)->context(...);
            $logger = $c->get(LoggerInterface::class);
            $clear = static function () use ($c):void {
                if (class_exists(\OCA\Circles\CirclesManager::class, false)) { $c->get(\OCA\Circles\CirclesManager::class)->stopSession(); }
            };
            $operation = new \OCA\Mcp\Modules\Deck\Files\Linking\CardFileLinking($logger, static function () use ($c, $gateway, $logger, $clear): array {
                $caller = $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class);
                $grants = $c->get(\OCA\Mcp\Access\Grants\GrantPolicy::class);
                $guard = $c->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class);
                $shares = $c->get(\OCP\Share\IManager::class);
                $config = $c->get(\OCP\IConfig::class);
                $apps = $c->get(\OCP\App\IAppManager::class);
                $users = $c->get(\OCP\IUserManager::class);
                $plans = $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class);
                $native = new \OCA\Mcp\Modules\Deck\Files\Linking\NativeCardFileLink($shares, $caller, static fn()=> $c->get(\OCA\Deck\Sharing\DeckShareProvider::class));
                $policy = new \OCA\Mcp\Modules\Deck\Files\Linking\CardLinkPolicy($shares, $config, $apps, $users, $c->get(\OCP\IUserSession::class), static fn()=> $c->get('OCA\\Guests\\GuestManager'));
                $directory = $c->get(DeckRecipientDirectory::class);
                $recipients = new \OCA\Mcp\Modules\Deck\Recipients\BoardRecipientResolver($gateway, $grants, $directory, $plans, $logger, $clear);
                $prepare = new \OCA\Mcp\Modules\Deck\Files\Linking\CardFileLinkPreparation($gateway, $grants, $guard, new \OCA\Mcp\Modules\Deck\Files\CardFileNodeAccess($guard, $c->get(\OCP\Files\IRootFolder::class)), $policy, $native, $c->get(\OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class), $recipients, $clear);
                return ['prepare'=>$prepare, 'native'=>$native, 'plans'=>new \OCA\Mcp\Modules\Deck\Files\Linking\CardLinkPlan($plans), 'shared'=>$c->get(\OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class)];
            }, static function () use ($c): void {
                if (class_exists(\OCA\Circles\CirclesManager::class, false)) {
                    $c->get(\OCA\Circles\CirclesManager::class)->stopSession();
                }
            });
            return new \OCA\Mcp\Modules\Deck\Tools\LinkCardFile($operation, $c->get(\OCA\Mcp\Modules\Deck\Execution\PreviewBoundary::class));
        });
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListCardFiles::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Tools\ListCardFiles(new \OCA\Mcp\Modules\Deck\Files\CardFileOperations($c->get(LoggerInterface::class), static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Files\CardFiles::class), $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class), static fn()=> $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class), false)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\UnlinkCardFile::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Tools\UnlinkCardFile(new \OCA\Mcp\Modules\Deck\Files\CardFileOperations($c->get(LoggerInterface::class), static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Files\CardFiles::class), $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class), static fn()=> $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class), true), $c->get(\OCA\Mcp\Modules\Deck\Execution\PreviewBoundary::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ReadCardDependencies::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Tools\ReadCardDependencies(new \OCA\Mcp\Modules\Deck\Dependencies\DependencyReading($c->get(\OCA\Mcp\Modules\Deck\Dependencies\DependencyAccess::class), $c->get(LoggerInterface::class), $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class))));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\AddCardDependency::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Tools\AddCardDependency(new \OCA\Mcp\Modules\Deck\Dependencies\DependencyEditing($c->get(\OCA\Mcp\Modules\Deck\Dependencies\DependencyAccess::class), $c->get(LoggerInterface::class), $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class), static fn()=> $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class), true), $c->get(\OCA\Mcp\Modules\Deck\Execution\PreviewBoundary::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\RemoveCardDependency::class, static fn(ContainerInterface $c)=> new \OCA\Mcp\Modules\Deck\Tools\RemoveCardDependency(new \OCA\Mcp\Modules\Deck\Dependencies\DependencyEditing($c->get(\OCA\Mcp\Modules\Deck\Dependencies\DependencyAccess::class), $c->get(LoggerInterface::class), $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class), static fn()=> $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class), false), $c->get(\OCA\Mcp\Modules\Deck\Execution\PreviewBoundary::class)));
        $context->registerService(DeckRecipientDirectory::class, static fn (ContainerInterface $c) => new DeckRecipientDirectory(
            static fn () => $c->get(\OCA\Deck\Db\AclMapper::class), static fn () => $c->get(\OCA\Deck\Service\CirclesService::class),
            $c->get(IUserManager::class), $c->get(\OCP\IGroupManager::class), $c->get(IAppManager::class),
            static fn () => $c->get(\OCA\Circles\CirclesManager::class)->stopSession(),
        ));
        $context->registerService(CommentAudience::class, static fn (ContainerInterface $c) => new CommentAudience(
            $c->get(DeckRecipientDirectory::class), $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class),
            static function () use ($c): void {
                if (class_exists(\OCA\Circles\CirclesManager::class, false)) {
                    $c->get(\OCA\Circles\CirclesManager::class)->stopSession();
                }
            },
        ));
        $context->registerService(CommentAccess::class, static fn (ContainerInterface $c) => new NativeCommentAccess(
            $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCaller::class),
            static fn () => $c->get(\OCA\Deck\Service\CardService::class),
            static fn () => $c->get(\OCA\Deck\Db\CardMapper::class),
            static fn (string $uid) => new \OCA\Deck\Service\CommentService(
                $c->get(\OCP\Comments\ICommentsManager::class), $c->get(\OCA\Deck\Service\PermissionService::class),
                $c->get(\OCA\Deck\Db\CardMapper::class), $c->get(IUserManager::class), $c->get(LoggerInterface::class), $uid,
            ),
            $c->get(CommentAudience::class)->fingerprint(...),
            static fn (string $uid, $card, int $boardId) => $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class)
                ->forUser($uid)->reference($card, $boardId),
            static fn (string $uid): string => $c->get(IUserManager::class)->get($uid)?->getDisplayName() ?? $uid,
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListComments::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ListComments(
            $c->get(CommentOperations::class), $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\AddComment::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\AddComment(
            $c->get(CommentOperations::class), $c->get(\OCA\Mcp\Modules\Deck\Execution\PreviewBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
        ));
        $context->registerService(DeckModule::class, static fn (ContainerInterface $c): DeckModule => new DeckModule([
            'deck_list_comments' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ListComments::class),
            'deck_add_comment' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\AddComment::class),
            'deck_list_labels' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\ListLabels::class),
            'deck_edit_card_labels' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\EditCardLabels::class),
            'deck_link_card_file' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\LinkCardFile::class),
            'deck_list_card_files' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\ListCardFiles::class),
            'deck_unlink_card_file' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\UnlinkCardFile::class),
            'deck_read_card_dependencies' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\ReadCardDependencies::class),
            'deck_add_card_dependency' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\AddCardDependency::class),
            'deck_remove_card_dependency' => static fn()=> $c->get(\OCA\Mcp\Modules\Deck\Tools\RemoveCardDependency::class),
            'deck_list_boards' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ListBoards::class),
            'deck_list_stacks' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ListStacks::class),
            'deck_list_cards' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ListCards::class),
            'deck_read_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\ReadCard::class),
            'deck_create_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\CreateCard::class),
            'deck_edit_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\EditCard::class),
            'deck_move_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\MoveCard::class),
            'deck_delete_card' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\DeleteCard::class),
            'deck_search_cards' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\SearchCards::class),
            'deck_followup_cards' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\FollowupCards::class),
            'deck_create_board' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\CreateBoard::class),
            'deck_create_stack' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\CreateStack::class),
            'deck_delete_stack' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\DeleteStack::class),
            'deck_delete_board' => static fn () => $c->get(\OCA\Mcp\Modules\Deck\Tools\DeleteBoard::class),
        ], $c->get(\OCA\Mcp\Modules\Deck\Access\DeckAvailability::class), $c->get(\OCA\Mcp\Modules\Deck\Access\DeckCapabilities::class)));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListBoards::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ListBoards(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\BoardReader::class)->listBoards(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListStacks::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ListStacks(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\StackReader::class)->listStacks(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ListCards::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ListCards(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\CardReader::class)->listCards(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\ReadCard::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\ReadCard(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\CardReader::class)->findCard(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\SearchCards::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\SearchCards(
            $c->get(\OCA\Mcp\Modules\Deck\Reading\CardSearchReader::class)->searchCards(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Deck\Tools\FollowupCards::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Deck\Tools\FollowupCards(
            $c->get(\OCA\Mcp\Modules\Deck\Followup\FollowupQuery::class)->followupCards(...),
            $c->get(\OCA\Mcp\Modules\Deck\Execution\ResultBoundary::class),
            $c->get(\OCA\Mcp\Modules\Deck\Presentation\DeckFormatterFactory::class),
        ));
        $context->registerService(\OCA\Mcp\Platform\FileAccess\References\FileReferenceScope::class,
            static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Access\ResourceScope\FilesReferenceScope::class));
        $context->registerService(FilesModule::class, static fn (ContainerInterface $c): FilesModule => new FilesModule([
            'files_list' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ListFiles::class),
            'files_search' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\SearchFiles::class),
            'files_tree' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReadTree::class),
            'files_mkdir' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\MakeDirectory::class),
            'files_copy' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CopyFile::class),
            'files_move' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\MoveFile::class),
            'files_move_batch' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\MoveBatch::class),
            'files_undo_batch' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\UndoBatch::class),
            'files_read' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReadFile::class),
            'files_edit' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\EditFile::class),
            'files_replace' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReplaceText::class),
            'files_checkout' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CheckoutFile::class),
            'files_versions_list' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ListVersions::class),
            'files_version_read' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ReadVersion::class),
            'files_version_restore' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\RestoreVersion::class),
            'files_image_view' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ViewImage::class),
            'files_images_view' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ViewImages::class),
            'files_image_search' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\SearchImages::class),
            'files_get_image_description' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\GetImageDescription::class),
            'files_set_image_description' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\SetImageDescription::class),
            'files_list_tags' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ListTags::class),
            'files_create_tag' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CreateTag::class),
            'files_set_tags' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\SetFileTags::class),
            'files_create_upload_link' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CreateUploadLink::class),
            'files_list_shares' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ListShares::class),
            'files_share' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\ShareFile::class),
            'files_unshare' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\UnshareFile::class),
            'files_upload' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\UploadFile::class),
            'files_create' => static fn () => $c->get(\OCA\Mcp\Modules\Files\Tools\CreateFile::class),
        ], static fn () => $c->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class)->assertScopeReadable()));
        $context->registerService(\OCA\Mcp\Modules\Files\Tools\SearchFiles::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Tools\SearchFiles => new \OCA\Mcp\Modules\Files\Tools\SearchFiles(
                $c->get(IRootFolder::class),
                $c->get(\OCA\Mcp\Modules\Files\Search\FileSearch::class),
                static function (string $uid) use ($c): bool {
                    return $c->get(ToolRegistry::class)->allowsTool($uid, 'files_get_image_description');
                },
            ));
        $context->registerService(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability(
            $c->get(GrantPolicy::class), $c->get(IAppManager::class), $c->get(IUserManager::class), $c->get(\OCA\Mcp\Access\Guests\GuestRestrictions::class),
            $c->get(\OCA\Mcp\OAuth\ConnectionContext::class),
        ));
        $context->registerService(\OCA\Mcp\Mcp\Tools\Execution\ToolExecutor::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Tools\Execution\ToolExecutor(
            $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class), $c->get(LoggerInterface::class),
        ));
        $context->registerService(ToolRegistry::class, static fn (ContainerInterface $c): ToolRegistry => new ToolRegistry(
            array_map(static fn (string $class) => $c->get($class), self::MODULES),
            $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class),
            $c->get(\OCA\Mcp\Mcp\Tools\Execution\ToolExecutor::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Files\Contracts\FileResourceReader::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Contracts\FileResourceReader => new \OCA\Mcp\Modules\Files\Reading\NextcloudFileResourceReader(
                $c->get(IRootFolder::class), $c->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class), new TextExtractor($c->get(ITempManager::class)),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Versions\VersionReader::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Versions\VersionReader => new \OCA\Mcp\Modules\Files\Versions\VersionReader(
                $c->get(IAppManager::class), $c->get(IUserManager::class), $c->get(TextExtractor::class),
                $c->get(\OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class),
                static fn () => $c->get(\OCA\Mcp\Modules\Files\Versions\VersionReader::MANAGER),
                $c->get(\OCA\Mcp\Modules\Files\Reading\OcrSupport::class),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Descriptions\DescriptionService::class,
            static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Files\Descriptions\DescriptionService(
                $c->get(IRootFolder::class),
                static fn () => $c->get(\OCP\FilesMetadata\IFilesMetadataManager::class),
                $c->get(\OCA\Mcp\Access\BackupPolicy\BackupLocation::class),
                $c->get(\OCA\Mcp\Modules\Files\Descriptions\EmbeddedDescriptionReader::class),
                $c->get(\OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class),
                $c->get(\OCA\Mcp\Platform\FileAccess\Sharing\SharedWriteGuard::class),
                $c->get(\OCA\Mcp\Modules\Files\Descriptions\DescriptionIndexWriter::class),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Images\ImageMetadata::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Images\ImageMetadata => new \OCA\Mcp\Modules\Files\Images\ImageMetadata(
                $c->get(\OCA\Mcp\Platform\FileAccess\Sharing\NodeAccessInfo::class),
                $c->get(\OCP\SystemTag\ISystemTagManager::class), $c->get(\OCP\SystemTag\ISystemTagObjectMapper::class), $c->get(IUserManager::class),
                static fn () => $c->get(\OCA\Mcp\Modules\Files\Images\ImageMetadata::METADATA_SERVICE),
            ));
        $context->registerService(\OCA\Mcp\Modules\Files\Reorganization\MoveReport::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\Files\Reorganization\MoveReport => new \OCA\Mcp\Modules\Files\Reorganization\MoveReport(
                static fn () => $c->get(\OCA\Mcp\Modules\Files\Reorganization\MoveReport::VERSIONS_MANAGER),
                $c->get(\OCP\Share\IManager::class), $c->get(IAppManager::class),
            ));
        $context->registerService(\OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader => new \OCA\Mcp\Modules\Notes\Resources\NextcloudNoteResourceReader(new \OCA\Mcp\Modules\Notes\Storage\NotesFolders($c->get(IRootFolder::class), $c->get(IConfig::class)), new \OCA\Mcp\Modules\Notes\Storage\NoteLookup(), new \OCA\Mcp\Modules\Notes\Content\NoteContent(), $c->get(VisibilityGuard::class)));
        $context->registerService(NotesModule::class, static fn (ContainerInterface $c): NotesModule => new NotesModule([
            'notes_list' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\ListNotes::class),
            'notes_search' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\SearchNotes::class),
            'notes_read' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\ReadNote::class),
            'notes_create' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\CreateNote::class),
            'notes_edit' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\EditNote::class),
            'notes_move' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\MoveNote::class),
            'notes_delete' => static fn () => $c->get(\OCA\Mcp\Modules\Notes\Tools\DeleteNote::class),
        ]));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\ListNotes::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\ListNotes => new \OCA\Mcp\Modules\Notes\Tools\ListNotes($c->get(\OCA\Mcp\Modules\Notes\Reading\NoteLister::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\SearchNotes::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\SearchNotes => new \OCA\Mcp\Modules\Notes\Tools\SearchNotes($c->get(\OCA\Mcp\Modules\Notes\Search\NoteSearch::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\ReadNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\ReadNote => new \OCA\Mcp\Modules\Notes\Tools\ReadNote($c->get(\OCA\Mcp\Modules\Notes\Reading\NoteReader::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\CreateNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\CreateNote => new \OCA\Mcp\Modules\Notes\Tools\CreateNote($c->get(\OCA\Mcp\Modules\Notes\Creation\NoteCreator::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\EditNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\EditNote => new \OCA\Mcp\Modules\Notes\Tools\EditNote($c->get(\OCA\Mcp\Modules\Notes\Editing\NoteEditor::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\MoveNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\MoveNote => new \OCA\Mcp\Modules\Notes\Tools\MoveNote($c->get(\OCA\Mcp\Modules\Notes\Moving\NoteMover::class)));
        $context->registerService(\OCA\Mcp\Modules\Notes\Tools\DeleteNote::class, static fn (ContainerInterface $c): \OCA\Mcp\Modules\Notes\Tools\DeleteNote => new \OCA\Mcp\Modules\Notes\Tools\DeleteNote($c->get(\OCA\Mcp\Modules\Notes\Deletion\NoteDeleter::class)));
        $context->registerService(\OCA\Mcp\Modules\Logs\LogsModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Logs\LogsModule([
            'logs_list' => static fn () => $c->get(\OCA\Mcp\Modules\Logs\Tools\ListLogs::class),
            'logs_analyze' => static fn () => $c->get(\OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs::class),
        ], static fn () => $c->get(\OCA\Mcp\Access\Logs\LogsAccess::class), static fn () => $c->get(\OCA\Mcp\Modules\Logs\Audit\LogAudit::class)));
        $context->registerService(\OCA\Mcp\Modules\Logs\Tools\ListLogs::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Logs\Tools\ListLogs($c->get(\OCA\Mcp\Modules\Logs\Listing\ListEntries::class)));
        $context->registerService(\OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Logs\Tools\AnalyzeLogs($c->get(\OCA\Mcp\Modules\Logs\Analysis\AnalyzeEntries::class)));
        $context->registerService(\OCA\Mcp\Modules\People\PeopleModule::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\People\PeopleModule => new \OCA\Mcp\Modules\People\PeopleModule([
                'users_search' => static fn () => $c->get(\OCA\Mcp\Modules\People\Tools\SearchUsers::class),
            ]));
        $context->registerService(\OCA\Mcp\Modules\People\Tools\SearchUsers::class,
            static fn (ContainerInterface $c): \OCA\Mcp\Modules\People\Tools\SearchUsers => new \OCA\Mcp\Modules\People\Tools\SearchUsers(
                $c->get(\OCA\Mcp\Modules\People\Search\PeopleSearch::class),
            ));
        $context->registerService(\OCA\Mcp\Modules\Forms\FormsNativeReader::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Forms\FormsNativeReader(
            $c->get(IAppManager::class), $c->get(IUserManager::class),
            static fn (string $class) => $c->get($class), static fn () => $c->get(IUserSession::class)->getUser()?->getUID(),
        ));
        $context->registerService(\OCA\Mcp\Modules\Forms\FormsReader::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Modules\Forms\FormsNativeReader::class));
        $context->registerService(\OCA\Mcp\Modules\Forms\FormsResponseReader::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Modules\Forms\FormsNativeReader::class));
        $context->registerService(\OCA\Mcp\Modules\Forms\FormsModule::class, static fn (ContainerInterface $c) => \OCA\Mcp\Modules\Forms\FormsModule::withReader(
            $c->get(\OCA\Mcp\Modules\Forms\FormsReader::class), $c->get(\OCA\Mcp\Modules\Forms\FormsResponseReader::class),
            $c->get(\OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter::class), $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class),
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Integration\MailServices::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Integration\MailServices(
            $c->get(IAppManager::class), static fn (string $class) => $c->get($class), $c->get(IUserManager::class)));
        $context->registerService(\OCA\Mcp\Modules\Mail\Integration\MailBoundedReads::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Integration\MailBoundedReads(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class), $c->get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class), $c->get(\OCP\IConfig::class)));
        $context->registerService(\OCA\Mcp\Modules\Mail\Contracts\MailReader::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Modules\Mail\Integration\MailGateway::class));
        foreach ([\OCA\Mcp\Modules\Mail\Tools\SearchMessages::class, \OCA\Mcp\Modules\Mail\Tools\ReadMessage::class, \OCA\Mcp\Modules\Mail\Tools\ReadThread::class] as $tool) {
            $context->registerService($tool, static fn (ContainerInterface $c) => new $tool(
                $c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class),
                $c->get(\OCA\Mcp\Modules\Mail\Integration\MailGateway::class),
                $c->get(UserTimezone::class),
                $c->get(\OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks::class),
            ));
        }
        $context->registerService(\OCA\Mcp\Mcp\Operations\OperationStore::class, static fn(ContainerInterface $c) => $c->get(\OCA\Mcp\Db\OperationsMapper::class));
        $context->registerService(\OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Drafts\MailDraftOperations(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class), $c->get(\OCA\Mcp\Modules\Mail\Integration\MailGateway::class),
            $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class), $c->get(\OCA\Mcp\Modules\Mail\Composition\ReplyComposer::class),
            $c->get(\OCA\Mcp\Modules\Mail\Files\MailFilesSource::class), $c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class), $c->get(\OCA\Mcp\Modules\Mail\Composition\ForwardComposer::class), $c->get(\OCA\Mcp\Modules\Mail\Integration\NativeDraftLinks::class), $c->get(\OCA\Mcp\Modules\Mail\Integration\NativeDraftWrites::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Operations\MailOperationCleanup::class,static fn(ContainerInterface $c)=>new \OCA\Mcp\Modules\Mail\Operations\MailOperationCleanup(
            $c->get(\OCA\Mcp\Db\OperationsMapper::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class),$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $c->get(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class),$c->get(\OCA\Mcp\Db\MailProbeSlotsMapper::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\NativeDraftWrites::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Files\MailFilesSource::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Files\MailFilesSource(
            $c->get(IRootFolder::class), $c->get(\OCA\Mcp\Access\ResourceScope\FilesAccessGuard::class), $c->get(\OCA\Mcp\Access\BackupPolicy\BackupLocation::class),
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class), static fn(string $uid, string $tool): bool => $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class)->allowed(
                ($tool === 'files_read' ? \OCA\Mcp\Modules\Files\FilesToolCatalog::definitions() : \OCA\Mcp\Modules\Mail\MailToolCatalog::definitions())[$tool], $uid), $c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Composition\ForwardComposer::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Composition\ForwardComposer(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailMessageLocator::class), static function(string $uid)use($c):\OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler {
                $rows=new \OCA\Mcp\Modules\Mail\Presentation\MailMessageRows($c->get(UserTimezone::class)->forUser($uid),$c->get(\OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks::class));
                return new \OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler($c->get(\OCA\Mcp\Modules\Mail\Integration\MailMessageLocator::class),new \OCA\Mcp\Modules\Mail\Integration\MailBodyReader($c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class)),$rows,new \OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer($rows));
            },$c->get(\OCA\Mcp\Modules\Mail\Attachments\MailAttachmentAccess::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class),static fn(string $uid,string $tool):bool=>self::mailToolAllowed($c,$uid,$tool),$c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Composition\ReplyComposer::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Composition\ReplyComposer(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailMessageLocator::class), static function(string $uid) use ($c): \OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler {
                $rows = new \OCA\Mcp\Modules\Mail\Presentation\MailMessageRows($c->get(UserTimezone::class)->forUser($uid), $c->get(\OCA\Mcp\Modules\Mail\Presentation\Link\MailLinks::class));
                return new \OCA\Mcp\Modules\Mail\Handlers\ReadMessageHandler($c->get(\OCA\Mcp\Modules\Mail\Integration\MailMessageLocator::class),
                    new \OCA\Mcp\Modules\Mail\Integration\MailBodyReader($c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class)), $rows,
                    new \OCA\Mcp\Modules\Mail\Presentation\MailMessageAnswer($rows));
            },
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class), static fn(string $uid, string $tool): bool => $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class)->allowed(
                ($tool === 'files_read' ? \OCA\Mcp\Modules\Files\FilesToolCatalog::definitions() : \OCA\Mcp\Modules\Mail\MailToolCatalog::definitions())[$tool], $uid)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Operations\MailOperationReader::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Operations\MailOperationReader(
            $c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class), static fn(string $uid, string $tool): bool => $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class)->allowed(
                ($tool === 'files_read' ? \OCA\Mcp\Modules\Files\FilesToolCatalog::definitions() : \OCA\Mcp\Modules\Mail\MailToolCatalog::definitions())[$tool], $uid),
            static fn(string $uid,string $id):?array=>$c->get(\OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup::class)->pendingNativeEntry($uid,$id)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway::class), $c->get(\OCA\Mcp\Db\MailSetupWorkflowsMapper::class),
            $c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class), $c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class), $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $c->get(\OCA\Mcp\Modules\Mail\Integration\NativeAccountSetupLinks::class), static fn(string $uid,string $tool):bool=>self::mailToolAllowed($c,$uid,$tool),$c->get(\OCA\Mcp\Modules\Mail\Integration\NativeSetupEvidence::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Connection\MailConnection::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Connection\MailConnection(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway::class),$c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class),$c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class),
            $c->get(\OCA\Mcp\Db\MailProbeSlotsMapper::class),$c->get(\OCA\Mcp\Modules\Mail\Connection\NativeConnectionProbe::class),$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),$c->get(\OCA\Mcp\Modules\Mail\Accounts\MailAccountSetup::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Connection\NativeConnectionProbe::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Connection\NativeConnectionProbe(
            $c->get(\OCA\Mcp\Platform\Process\NativeProbeProcess::class),$c->get(\OCA\Mcp\Modules\Mail\Connection\ProbeTicket::class),$c->get(\OCA\Mcp\OAuth\ConnectionContext::class),
            static function()use($c):?array {
                try{$root=dirname($c->get(IAppManager::class)->getAppPath('files'),2);$cli=PHP_BINDIR.'/php';
                    if(!is_executable($cli)||!is_file($root.'/occ')||!is_file($root.'/lib/base.php'))return null;
                    return ['command'=>[$cli,$root.'/occ','mcp:mail-connection-probe','--no-interaction','--no-ansi'],'cwd'=>$root];
                }catch(\Throwable){return null;}
            }
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Connection\NativeConnectionProbeWorker::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Connection\NativeConnectionProbeWorker(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class),
            $c->get(\OCA\Mcp\Modules\Mail\Integration\NativeAccountSetupLinks::class),$c->get(\OCP\IConfig::class),$c->get(\OCA\Mcp\Modules\Mail\Connection\ProbeTicket::class),
            $c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class),static function(string $uid,int $connection,array $sources=[])use($c):bool {
                $user=$c->get(IUserManager::class)->get($uid);if($user===null||!$user->isEnabled())return false;
                $c->get(\OCP\IUserSession::class)->setUser($user);$c->get(IAppManager::class)->loadApp('mail');
                $context=$c->get(\OCA\Mcp\OAuth\ConnectionContext::class);$context->set($connection);
                $selection=$c->get(\OCA\Mcp\OAuth\ConnectionToolsStore::class)->get($uid,$connection);
                if($selection===null)return false;$context->setSelection($selection['tools'],$selection['variants']);
                return $c->get(GrantPolicy::class)->within($uid,static function()use($c,$uid,$sources):bool {
                    if(!self::mailToolAllowed($c,$uid,'mail_test_connection')||count($sources)>8)return false;
                    foreach($sources as $tool)if(!is_string($tool)||!isset(\OCA\Mcp\Modules\Mail\MailToolCatalog::definitions()[$tool])||!self::mailToolAllowed($c,$uid,$tool))return false;
                    return true;
                });
            }
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Integration\MailMutationTarget::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Integration\MailMutationTarget(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailMessageLocator::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailSettingsGateway::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailCapabilities::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailMoveTarget::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailboxMutationTarget::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailTagTarget::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailRefreshTarget::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary(
            $c->get(IAppManager::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailDraftStorage::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Integration\MailCapabilities::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Integration\MailCapabilities(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class),$c->get(IAppManager::class),$c->get(\OCP\IConfig::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailNativeBoundary::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailTagBoundary::class)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Mutations\NativeMutationRunner::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Mutations\NativeMutationRunner(
            $c->get(\OCA\Mcp\Modules\Mail\Mutations\MutationTicket::class),$c->get(\OCA\Mcp\OAuth\ConnectionContext::class),$c->get(\OCA\Mcp\Platform\Process\NativeMutationProcess::class),
            static function()use($c):?array {
                try{$root=dirname($c->get(IAppManager::class)->getAppPath('files'),2);$cli=PHP_BINDIR.'/php';
                    if(!is_executable($cli)||!is_file($root.'/occ')||!is_file($root.'/lib/base.php'))return null;
                    return ['command'=>[$cli,$root.'/occ','mcp:mail-mutation','--no-interaction','--no-ansi'],'cwd'=>$root];
                }catch(\Throwable){return null;}
            }
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Mutations\NativeMutationWorker::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Mutations\NativeMutationWorker(
            $c->get(\OCA\Mcp\Modules\Mail\Mutations\MutationTicket::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailMutationTarget::class),$c->get(\OCA\Mcp\Modules\Mail\Integration\MailServices::class),
            $c->get(\OCA\Mcp\Mcp\Operations\OperationStore::class),$c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class),$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            static function(string $uid,int $connection,string $tool)use($c):bool {
                $user=$c->get(IUserManager::class)->get($uid);if($user===null||!$user->isEnabled())return false;
                $c->get(\OCP\IUserSession::class)->setUser($user);$c->get(IAppManager::class)->loadApp('mail');
                $context=$c->get(\OCA\Mcp\OAuth\ConnectionContext::class);$context->set($connection);
                $selection=$c->get(\OCA\Mcp\OAuth\ConnectionToolsStore::class)->get($uid,$connection);
                if($selection===null)return false;$context->setSelection($selection['tools'],$selection['variants']);
                return $c->get(GrantPolicy::class)->within($uid,static fn():bool=>self::mailToolAllowed($c,$uid,$tool));
            }
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Tags\MailTagCreation::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Tags\MailTagCreation(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailTagGateway::class),$c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class),$c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class),$c->get(\OCP\IDBConnection::class),$c->get(\OCA\Mcp\Db\MailProbeSlotsMapper::class),$c->get(\OCP\AppFramework\Utility\ITimeFactory::class),static fn(string $uid,string $tool):bool=>self::mailToolAllowed($c,$uid,$tool)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\Accounts\MailIdentityAdministration::class, static fn(ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Accounts\MailIdentityAdministration(
            $c->get(\OCA\Mcp\Modules\Mail\Integration\MailAliasGateway::class),$c->get(\OCA\Mcp\Mcp\Confirmation\PlanState::class),$c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class),$c->get(\OCP\IDBConnection::class),static fn(string $uid,string $tool):bool=>self::mailToolAllowed($c,$uid,$tool)
        ));
        $context->registerService(\OCA\Mcp\Modules\Mail\MailModule::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\MailModule([
            'mail_list_accounts' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ListAccounts::class),
            'mail_list_mailboxes' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ListMailboxes::class),
            'mail_search_messages' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\SearchMessages::class),
            'mail_read_message' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReadMessage::class),
            'mail_read_message_metadata' => static fn () => new \OCA\Mcp\Modules\Mail\Tools\ReadMessageMetadata($c->get(\OCA\Mcp\Modules\Mail\Integration\MailBoundedReads::class)),
            'mail_read_outbox' => static fn () => new \OCA\Mcp\Modules\Mail\Tools\ReadOutbox($c->get(\OCA\Mcp\Modules\Mail\Integration\MailBoundedReads::class)),
            'mail_read_thread' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReadThread::class),
            'mail_read_attachment' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReadAttachment::class),
            'mail_save_attachment' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\SaveAttachment::class),
            'mail_read_drafts' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReadDrafts::class),
            'mail_write_draft' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\WriteDraft::class),
            'mail_delete_draft' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\DeleteDraft::class),
            'mail_attach_files' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\AttachFiles::class),
            'mail_get_operation' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\GetOperation::class),
            'mail_list_operations' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ListOperations::class),
            'mail_read_account_settings' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReadAccountSettings::class),
            'mail_write_account_settings' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\WriteAccountSettings::class),
            'mail_read_signature' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReadSignature::class),
            'mail_write_signature' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\WriteSignature::class),
            'mail_read_connection' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReadConnection::class),
            'mail_configure_connection' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ConfigureConnection::class),
            'mail_test_connection' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\TestConnection::class),
            'mail_add_account' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\AddAccount::class),
            'mail_complete_account_setup' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\CompleteAccountSetup::class),
            'mail_set_message_flag' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\SetMessageFlag::class),
            'mail_move_message' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\MoveMessage::class),
            'mail_archive_message' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ArchiveMessage::class),
            'mail_trash_message' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\TrashMessage::class),
            'mail_restore_message' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\RestoreMessage::class),
            'mail_create_mailbox' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\CreateMailbox::class),
            'mail_rename_mailbox' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\RenameMailbox::class),
            'mail_set_mailbox_subscription' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\SetMailboxSubscription::class),
            'mail_create_tag' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\CreateTag::class),
            'mail_refresh_mailbox' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\RefreshMailbox::class),
            'mail_reconcile_operation' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ReconcileOperation::class),
            'mail_add_alias' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\AddAlias::class),
            'mail_update_alias' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\UpdateAlias::class),
            'mail_delete_alias' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\DeleteAlias::class),
            'mail_set_message_tag' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\SetMessageTag::class),
            'mail_list_tags' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ListTags::class),
            'mail_list_aliases' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ListAliases::class),
            'mail_configure_mailboxes' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\ConfigureMailboxes::class),
            'mail_delete_account' => static fn () => $c->get(\OCA\Mcp\Modules\Mail\Tools\DeleteAccount::class),

        ], $c->get(\OCA\Mcp\Mcp\Operations\OperationJournal::class), $c->get(\OCA\Mcp\Modules\Mail\Operations\MailOperationReader::class)));
        $context->registerService(\OCA\Mcp\Modules\Mail\Tools\ListAccounts::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Tools\ListAccounts($c->get(\OCA\Mcp\Modules\Mail\Accounts\AccountLister::class)));
        $context->registerService(\OCA\Mcp\Modules\Mail\Tools\ListMailboxes::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Modules\Mail\Tools\ListMailboxes($c->get(\OCA\Mcp\Modules\Mail\Mailboxes\MailboxLister::class)));
        $context->registerService(\OCA\Mcp\Modules\Files\Contracts\IncomingFileWriter::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\Modules\Files\Creation\IncomingFileCreation::class));
        $context->registerService(\OCA\Mcp\Modules\Files\Contracts\FileConnectionContext::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\OAuth\ConnectionContext::class));
        $context->registerService(\OCA\Mcp\Modules\Files\Contracts\CheckoutSelection::class, static fn (ContainerInterface $c) => $c->get(\OCA\Mcp\OAuth\ConnectionToolsStore::class));
        $context->registerService(\OCA\Mcp\OAuth\ConnectionContext::class, static fn () => new \OCA\Mcp\OAuth\ConnectionContext());
        $context->registerService(\OCA\Mcp\OAuth\ConnectionToolsStore::class, static fn (ContainerInterface $c) => new \OCA\Mcp\OAuth\ConnectionToolsStore(
            $c->get(\OCP\IDBConnection::class), $c->get(\OCP\AppFramework\Utility\ITimeFactory::class),
            $c->get(\OCA\Mcp\OAuth\TokenOwnerGate::class), $c->get(\OCA\Mcp\Db\AuthUsersMapper::class),
            $c->get(\OCA\Mcp\Db\PolicyStateMapper::class), $c->get(\OCA\Mcp\Db\OAuthClientsMapper::class),
        ));
        $context->registerService(PromptCatalog::class, static fn (ContainerInterface $c): PromptCatalog => new PromptCatalog($c->get(ToolRegistry::class)));
        $context->registerService(ResourceRegistry::class, static fn (ContainerInterface $c): ResourceRegistry => new ResourceRegistry($c->get(ToolRegistry::class), $c->get(GrantPolicy::class), $c->get(IAppManager::class), $c->get(IUserManager::class), $c->get(\OCA\Mcp\Modules\Files\Contracts\FileResourceReader::class), $c->get(\OCA\Mcp\Modules\Notes\Contracts\NoteResourceReader::class), $c->get(ToolRegistry::class)->guide(), $c->get(\OCA\Mcp\Access\Guests\GuestRestrictions::class)));
        $context->registerService(\OCA\Mcp\Mcp\Protocol\ServerIdentity::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Protocol\ServerIdentity($c->get(IAppManager::class), $c->get(\OCA\Mcp\Release\SourceOffer::class)));
        $context->registerService(\OCA\Mcp\Mcp\Protocol\ToolRequests::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Protocol\ToolRequests(
            $c->get(\OCA\Mcp\Mcp\Protocol\ServerIdentity::class),
            $c->get(ToolRegistry::class),
            new \OCA\Mcp\Mcp\Diagnostics\ToolCallDiagnostics($c->get(\OCP\IConfig::class), \OCA\Mcp\Modules\Files\Sharing\SharingMessages::diagnosticReason(...)),
        ));
        $context->registerService(\OCA\Mcp\Mcp\Protocol\PromptRequests::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Protocol\PromptRequests($c->get(\OCA\Mcp\Mcp\Protocol\ServerIdentity::class), $c->get(PromptCatalog::class), $c->get(GrantPolicy::class)));
        $context->registerService(\OCA\Mcp\Mcp\Protocol\ResourceRequests::class, static fn (ContainerInterface $c) => new \OCA\Mcp\Mcp\Protocol\ResourceRequests($c->get(\OCA\Mcp\Mcp\Protocol\ServerIdentity::class), $c->get(ResourceRegistry::class)));
        $context->registerService(McpProtocol::class, static fn (ContainerInterface $c): McpProtocol => new McpProtocol(
            $c->get(\OCA\Mcp\Mcp\Protocol\ServerIdentity::class),
            $c->get(\OCA\Mcp\Mcp\Protocol\ToolRequests::class),
            $c->get(\OCA\Mcp\Mcp\Protocol\PromptRequests::class),
            $c->get(\OCA\Mcp\Mcp\Protocol\ResourceRequests::class),
            $c->get(LoggerInterface::class),
        ));
    }

    private static function mailToolAllowed(ContainerInterface $c,string $uid,string $tool):bool {
        $definitions=\OCA\Mcp\Modules\Mail\MailToolCatalog::definitions();
        return isset($definitions[$tool]) && $c->get(\OCA\Mcp\Mcp\Tools\Discovery\ToolAvailability::class)->allowed($definitions[$tool],$uid);
    }

    /** @param IBootContext $context unused; the app needs no boot-time work */
    public function boot(IBootContext $context): void {
    }
}
