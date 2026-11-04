<?php

declare(strict_types=1);

/** Tiny Elementor type double to test registration without distributing Elementor itself. */
namespace Elementor {
require_once __DIR__ . '/../src/Support/I18n.php';
    abstract class Widget_Base {
        public function get_settings_for_display($id): mixed { return 0; }
        protected function start_controls_section(...$args): void {}
        protected function add_control(...$args): void {}
        protected function end_controls_section(...$args): void {}
    }
    final class Controls_Manager { public const NUMBER = 'number'; }
}
namespace {
    require __DIR__ . '/../src/Integrations/ElementorWidgets.php';
    $handlers = [];
    function add_action(string $name, callable $callback): void {
        global $handlers; $handlers[$name] = $callback;
    }
    function do_shortcode(string $shortcode): string { return 'RENDER:' . $shortcode; }
    class FakeWidgetManager {
        public array $widgets = [];
        public function register(object $widget): void { $this->widgets[$widget->get_name()] = $widget; }
    }
    \PESM\Integrations\ElementorWidgets::register();
    $manager = new FakeWidgetManager();
    $handlers['elementor/widgets/register']($manager);
    if (count($manager->widgets) !== 6) throw new \RuntimeException('Missing Elementor widgets.');
    $expected = ['pesm_events', 'pesm_event','pesm_seat_map','pesm_my_tickets','pesm_manager_dashboard','pesm_gate_scanner'];
    foreach ($expected as $name) {
        if (!isset($manager->widgets[$name])) throw new \RuntimeException('Missing widget ' . $name);
    }
    echo 'PASS: 6 Elementor widgets registered against isolated mock. Elementor/browser integration not tested.' . "\n";
}
