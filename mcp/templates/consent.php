<?php
/*
 * SPDX-FileCopyrightText: 2026 Sintax (Jhonatan Jaworski) <contato@sintax.tech>
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
/**
 * OAuth consent page (guest layout). Shows the client name from its metadata document next to the client_id host
 * and the redirect host; the form posts with the Nextcloud CSRF token.
 *
 * @var \OCP\IL10N $l
 * @var array $_
 */
\OCP\Util::addStyle('mcp', 'consent');
if ($_['pending'] !== null) {
    \OCP\Util::addScript('mcp', 'personal');
}
?>
<div class="mcp-consent">
<?php if ($_['error'] !== null): ?>
    <h2><?php p($l->t('Connection not possible')); ?></h2>
    <p class="mcp-error"><?php p($l->t('The authorization request is invalid.')); ?></p>
    <p class="mcp-hosts"><?php p($_['error']); ?></p>
<?php elseif ($_['unavailable']): ?>
    <h2><?php p($l->t('Connection not possible')); ?></h2>
    <p class="mcp-error"><?php p($l->t('MCP access for your account cannot be checked right now. Try connecting again shortly.')); ?></p>
<?php elseif ($_['blocked']): ?>
    <h2><?php p($l->t('MCP is not available for your account')); ?></h2>
    <p><?php p($l->t('%s cannot be connected because an administrator has not enabled MCP for your account.', [$_['client']])); ?></p>
<?php else: ?>
    <h2><?php p($l->t('Allow %s to access MCP with your account?', [$_['client']])); ?></h2>
    <p><?php p($l->t('Signed in as %s.', [$_['account']])); ?></p>
    <p><?php p($l->t('Only the tools you choose below will be available to this connection. You can change its tools or disconnect it later in your personal settings.')); ?></p>
    <p class="mcp-hosts">
        <?php p($l->t('Client: %s', [$_['nativeClient'] ? $l->t('Local program (native client)') : ($_['customClient'] ? $l->t('%s (added by your administrator)', [$_['client']]) : $_['clientHost'])])); ?><br>
        <?php p($l->t('Returns to: %s', [$_['redirectHost']])); ?>
    </p>
    <?php if ($_['loopback']): ?>
        <p class="mcp-warning"><?php p($l->t('This client returns to a program on your own computer. Only continue if you started this connection yourself.')); ?></p>
    <?php endif; ?>
    <form method="post" action="<?php p($_['action']); ?>">
        <input type="hidden" name="requesttoken" value="<?php p($_['requesttoken']); ?>">
        <input type="hidden" name="pending" value="<?php p($_['pending']); ?>">
        <input type="hidden" name="toolsSubmitted" value="1">
        <div class="mcp-consent-tools">
            <h3><?php p($l->t('Choose the tools this connection can use')); ?></h3>
            <p class="mcp-hosts"><?php p($l->t('With no data tools selected, only built-in MCP guidance and status tools remain; they reveal no user resources.')); ?></p>
            <?php $selectedTools = $_['selectedTools'] ?? []; $missingAction = $_['missingAction'] ?? null; ?>
            <div class="mcp-consent-toolbar" hidden>
                <output class="mcp-consent-count"><?php p($l->t('Selected tools')); ?>: <b data-count><?php p(count($selectedTools)); ?></b> / <?php p(array_sum(array_map('count', $_['toolsCatalog']))); ?></output>
                <div class="mcp-consent-choices">
                    <button type="button" data-select="all"><?php p($l->t('Select all tools')); ?></button>
                    <button type="button" data-select="none"><?php p($l->t('Clear selection')); ?></button>
                </div>
            </div>
            <?php foreach ($_['toolsCatalog'] as $catalog => $tools): ?>
                <?php $names = array_column($tools, 'name'); ?>
                <details class="mcp-consent-catalog" <?php if (in_array($missingAction, $names, true)) { print_unescaped('open'); } ?>>
                    <summary><?php p($l->t($_['catalogTitles'][$catalog])); ?> <span class="mcp-consent-catalog-count"><span data-progress hidden><span data-count><?php p(count(array_intersect($selectedTools, $names))); ?></span> / </span><?php p(count($tools)); ?></span></summary>
                    <div class="mcp-consent-choices" hidden>
                        <button type="button" data-select="all"><?php p($l->t('Select this catalog')); ?></button>
                        <button type="button" data-select="none"><?php p($l->t('Clear this catalog')); ?></button>
                    </div>
                    <?php foreach ($tools as $tool): ?>
                        <?php $selected = in_array($tool['name'], $selectedTools, true); ?>
                        <div class="mcp-consent-entry">
                            <label class="mcp-consent-tool">
                                <input class="mcp-consent-tool-toggle" type="checkbox" name="tools[]" value="<?php p($tool['name']); ?>" <?php if ($selected) { print_unescaped('checked'); } ?>>
                                <strong><?php p($l->t($tool['title'])); ?></strong>
                            </label>
                            <details class="mcp-consent-description">
                                <summary><?php p($l->t('Description')); ?></summary>
                                <p><?php p($tool['description']); ?></p>
                            </details>
                            <?php $approvedVariants = $tool['approvedVariants'] ?? []; ?>
                            <?php if ($approvedVariants !== []): ?>
                                <fieldset class="mcp-consent-variants">
                                    <legend><?php p($l->t('Allowed actions')); ?></legend>
                                    <?php foreach ($approvedVariants as $variant): ?>
                                        <?php $variantLabel = $variant === 'share' ? 'People and groups' : ($variant === 'link' ? 'Public links' : $variant); ?>
                                        <?php $selectedVariants = $_['toolVariants'][$tool['name']] ?? []; ?>
                                        <label><input type="checkbox" name="variants[<?php p($tool['name']); ?>][]" value="<?php p($variant); ?>" <?php if (in_array($variant, $selectedVariants, true)) { print_unescaped('checked'); } ?>><?php p($l->t($variantLabel)); ?></label>
                                    <?php endforeach; ?>
                                    <p class="mcp-error mcp-consent-action-error" role="alert" <?php if ($missingAction !== $tool['name']) { print_unescaped('hidden'); } ?>><?php p($l->t('Select at least one action for this tool.')); ?></p>
                                </fieldset>
                                <input type="hidden" name="variantTools[]" value="<?php p($tool['name']); ?>">
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <div class="mcp-actions">
            <button type="submit" name="decision" value="deny"><?php p($l->t('Cancel')); ?></button>
            <button type="submit" name="decision" value="allow" class="primary"><?php p($l->t('Allow')); ?></button>
        </div>
    </form>
<?php endif; ?>
</div>
