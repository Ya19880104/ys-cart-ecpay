<?php
declare(strict_types=1);

namespace YangSheep\Ecommerce\Admin\Partials;

abstract class EcpayAdminPartialStub
{
    protected static function text(array $args, string $key): string
    {
        return htmlspecialchars((string) ($args[$key] ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

final class YSAdminSurfacePartial extends EcpayAdminPartialStub
{
    public static function open(array $args = []): void
    {
        $title = (string) ($args['title'] ?? '');
        $description = (string) ($args['description'] ?? '');
        echo '<section class="ysca-surface" data-stub-partial="surface">';
        if ($title !== '' || $description !== '') {
            echo '<header>';
            if ($title !== '') {
                echo '<h2>' . self::text($args, 'title') . '</h2>';
            }
            if ($description !== '') {
                echo '<p>' . self::text($args, 'description') . '</p>';
            }
            echo '</header>';
        }
        echo '<div class="ysca-surface__body">';
    }
    public static function close(): void { echo '</div></section>'; }
}

final class YSAdminSectionPartial extends EcpayAdminPartialStub
{
    public static function open(array $args = []): void { echo '<section class="ysca-section" data-stub-partial="section" data-variant="' . self::text($args, 'variant') . '"><header><h2>' . self::text($args, 'title') . '</h2></header><div class="ysca-section__body">'; }
    public static function close(): void { echo '</div></section>'; }
}

final class YSAdminFieldPartial extends EcpayAdminPartialStub
{
    public static function open(array $args = []): void { echo '<div class="ysca-field" data-stub-partial="field">'; }
    public static function close(): void { echo '</div>'; }
}

final class YSAdminNoticePartial extends EcpayAdminPartialStub
{
    public static function render(array $args = []): void { echo '<div class="ysca-notice ysca-notice--' . self::text($args, 'tone') . '" data-stub-partial="notice">' . self::text($args, 'text') . '</div>'; }
}

final class YSAdminNavTabsPartial extends EcpayAdminPartialStub
{
    public static function render(array $args = []): void
    {
        echo '<nav class="ysca-tabs ysca-tabs--navigation" data-stub-partial="nav-tabs">';
        foreach ((array) ($args['items'] ?? []) as $item) {
            if (!is_array($item)) { continue; }
            echo '<a class="ysca-tab' . (!empty($item['active']) ? ' is-active' : '') . '" href="' . htmlspecialchars((string) ($item['href'] ?? ''), ENT_QUOTES, 'UTF-8') . '"' . (!empty($item['active']) ? ' aria-current="page"' : '') . '>' . htmlspecialchars((string) ($item['label'] ?? ''), ENT_QUOTES, 'UTF-8') . '</a>';
        }
        echo '</nav>';
    }
}

final class YSAdminButtonPartial extends EcpayAdminPartialStub
{
    public static function render(array $args = []): void
    {
        echo '<button type="' . self::text($args, 'type') . '" class="ysca-btn"' . (isset($args['id']) ? ' id="' . self::text($args, 'id') . '"' : '') . ' data-stub-partial="button">' . self::text($args, 'label') . '</button>';
    }
}
