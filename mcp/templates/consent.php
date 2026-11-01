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
?>
<style>
    .mcp-consent { max-width: 560px; max-height: calc(100vh - 32px); overflow-y: auto; margin: 0 auto; padding: 24px; border-radius: var(--border-radius-large, 12px);
        background: var(--color-main-background); color: var(--color-main-text); text-align: left; }
    .mcp-consent h2 { margin: 0 0 12px; font-size: 20px; }
    .mcp-consent p { margin: 8px 0; }
    .mcp-consent .mcp-hosts { color: var(--color-text-maxcontrast); font-size: 14px; }
    .mcp-consent .mcp-warning { color: var(--color-warning-text, var(--color-warning)); }
    .mcp-consent .mcp-error { color: var(--color-error-text, var(--color-error)); }
    .mcp-consent .mcp-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 20px; }
    .mcp-consent button.primary { background: var(--color-primary-element); color: var(--color-primary-element-text); }
    .mcp-consent-tools { margin-block: 18px; }
    .mcp-consent-catalog { border-top: 1px solid var(--color-border); }
    .mcp-consent-catalog summary { padding-block: 12px; font-weight: 700; cursor: pointer; }
    .mcp-consent-tool { display: flex; align-items: flex-start; gap: 10px; padding: 10px 4px; border-top: 1px solid var(--color-border); }
    .mcp-consent-tool > span { display: grid; gap: 3px; }
    .mcp-consent-variants { margin: 4px 0 8px 30px; padding: 8px 10px; border: 1px solid var(--color-border); border-radius: var(--border-radius); }
    .mcp-consent-variants legend { padding-inline: 4px; color: var(--color-text-maxcontrast); font-size: 13px; }
    .mcp-consent-variants label { display: inline-flex; align-items: center; gap: 8px; margin: 4px 16px 4px 0; }
    @media (max-width: 600px) { .mcp-consent { max-height: calc(100vh - 16px); padding: 16px; } }
</style>
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
    <p><?php p($l->t('The app will be able to use the MCP tools your administrator enabled for you. You can disconnect it at any time in your personal settings.')); ?></p>
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
            <?php foreach (($_['toolsCatalog'] ?? []) as $catalog => $tools): ?>
                <details class="mcp-consent-catalog" <?php if ($catalog === 'files') { print_unescaped('open'); } ?>>
                    <summary><?php p($catalog); ?></summary>
                    <?php foreach ($tools as $tool): ?>
                        <?php $selected = in_array($tool['name'], $_['selectedTools'] ?? [], true); ?>
                        <label class="mcp-consent-tool">
                            <input class="mcp-consent-tool-toggle" type="checkbox" name="tools[]" value="<?php p($tool['name']); ?>" <?php if ($selected) { print_unescaped('checked'); } ?>>
                            <span><strong><?php p($tool['title']); ?></strong><span class="mcp-hosts"><?php p($tool['description']); ?></span></span>
                        </label>
                        <?php $approvedVariants = $tool['approvedVariants'] ?? []; ?>
                        <?php if ($approvedVariants !== []): ?>
                            <fieldset class="mcp-consent-variants">
                                <legend><?php p($l->t('Allowed actions')); ?></legend>
                                <?php foreach ($approvedVariants as $variant): ?>
                                    <?php $variantLabel = $variant === 'share' ? 'People and groups' : ($variant === 'link' ? 'Public links' : $variant); ?>
                                    <?php $selectedVariants = $_['toolVariants'][$tool['name']] ?? []; ?>
                                    <label><input type="checkbox" name="variants[<?php p($tool['name']); ?>][]" value="<?php p($variant); ?>" <?php if (in_array($variant, $selectedVariants, true)) { print_unescaped('checked'); } ?> <?php if (!$selected || !in_array($variant, $selectedVariants, true)) { print_unescaped('disabled'); } ?>><?php p($l->t($variantLabel)); ?></label>
                                <?php endforeach; ?>
                            </fieldset>
                            <input type="hidden" name="variantTools[]" value="<?php p($tool['name']); ?>">
                        <?php endif; ?>
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
