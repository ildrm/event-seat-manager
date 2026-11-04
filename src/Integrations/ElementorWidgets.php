<?php

declare(strict_types=1);

namespace PESM\Integrations;

/** Loads only after Elementor's core types have been registered. */
final class ElementorWidgets
{
    public static function register(): void
    {
        add_action('elementor/widgets/register', static function ($manager): void {
            if (!class_exists('\\Elementor\\Widget_Base') || !method_exists($manager, 'register')) {
                return;
            }
            require_once __DIR__ . '/Elementor/Widget.php';
            foreach (['Events', 'Event', 'SeatMap', 'MyTickets', 'Manager', 'Gate'] as $name) {
                $class = 'PESM\\Integrations\\Elementor\\' . $name;
                $manager->register(new $class());
            }
        });
    }
}
