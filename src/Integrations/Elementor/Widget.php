<?php

declare(strict_types=1);

namespace PESM\Integrations\Elementor;

/** Server-rendered widgets reuse the existing secured shortcode entry points. */
abstract class Widget extends \Elementor\Widget_Base
{
    protected const SHORTCODE = '';
    protected const TITLE = '';
    protected const IDENTIFIER = '';
    protected const PARAMETER = '';

    public function get_name(): string { return 'pesm_' . static::IDENTIFIER; }
    public function get_title(): string { return \PESM\Support\I18n::text(static::TITLE); }
    public function get_icon(): string { return 'eicon-calendar'; }
    public function get_categories(): array { return ['general']; }

    protected function register_controls(): void
    {
        $this->start_controls_section('pesm_content', ['label' => (\PESM\Support\I18n::text('تنظیمات سامانه رویداد'))]);
        if (static::PARAMETER !== '') {
            $this->add_control('resource_id', [
                'label' => static::PARAMETER === 'session' ? (\PESM\Support\I18n::text('شناسه جلسه')) : (\PESM\Support\I18n::text('شناسه رویداد')),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'min' => 1, 'step' => 1, 'default' => 0,
            ]);
        }
        $this->end_controls_section();
    }

    protected function render(): void
    {
        $id = (int) ($this->get_settings_for_display('resource_id') ?? 0);
        if (static::PARAMETER !== '' && $id < 1) {
            echo ('<p ' . 'dir="' . \PESM\Support\I18n::direction() . '"' . \PESM\Support\I18n::text('>شناسه رویداد یا جلسه را در تنظیمات ابزارک وارد کنید.</p>'));
            return;
        }
        $param = static::PARAMETER === '' ? '' : ' ' . static::PARAMETER . '="' . $id . '"';
        // Current shortcodes enforce authentication and center scope for privileged content.
        echo do_shortcode('[' . static::SHORTCODE . $param . ']'); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
    }
}
final class Events extends Widget {
    protected const SHORTCODE = 'pesm_events'; protected const TITLE = 'فهرست رویدادها'; protected const IDENTIFIER = 'events';
}
final class Event extends Widget {
    protected const SHORTCODE = 'pesm_event'; protected const TITLE = 'جزئیات رویداد'; protected const IDENTIFIER = 'event'; protected const PARAMETER = 'id';
}
final class SeatMap extends Widget {
    protected const SHORTCODE = 'pesm_seat_map'; protected const TITLE = 'نقشه انتخاب صندلی'; protected const IDENTIFIER = 'seat_map'; protected const PARAMETER = 'session';
}
final class MyTickets extends Widget {
    protected const SHORTCODE = 'pesm_my_tickets'; protected const TITLE = 'بلیت‌های من'; protected const IDENTIFIER = 'my_tickets';
}
final class Manager extends Widget {
    protected const SHORTCODE = 'pesm_manager_dashboard'; protected const TITLE = 'داشبورد مدیر مرکز'; protected const IDENTIFIER = 'manager_dashboard';
}
final class Gate extends Widget {
    protected const SHORTCODE = 'pesm_gate_scanner'; protected const TITLE = 'اسکنر کنترل ورود'; protected const IDENTIFIER = 'gate_scanner';
}
