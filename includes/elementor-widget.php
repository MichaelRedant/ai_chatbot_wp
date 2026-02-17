<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('octopus_ai_register_elementor_widget')) {
    function octopus_ai_register_elementor_widget($widgets_manager)
    {
        if (!class_exists('\\Elementor\\Widget_Base')) {
            return;
        }

        if (!class_exists('Octopus_AI_Elementor_Chatbot_Widget')) {
            class Octopus_AI_Elementor_Chatbot_Widget extends \Elementor\Widget_Base
            {
                public function get_name()
                {
                    return 'octopus_ai_chatbot';
                }

                public function get_title()
                {
                    return 'Octopus AI Chatbot';
                }

                public function get_icon()
                {
                    return 'eicon-chat';
                }

                public function get_categories()
                {
                    return array('general');
                }

                public function get_keywords()
                {
                    return array('octopus', 'chatbot', 'ai', 'support');
                }

                protected function register_controls()
                {
                    if (!class_exists('\\Elementor\\Controls_Manager')) {
                        return;
                    }

                    $controls = \Elementor\Controls_Manager::class;

                    $this->start_controls_section(
                        'octopus_content_section',
                        array(
                            'label' => 'Inhoud',
                            'tab' => $controls::TAB_CONTENT,
                        )
                    );

                    $this->add_control(
                        'widget_title',
                        array(
                            'label' => 'Widget titel',
                            'type' => $controls::TEXT,
                            'placeholder' => 'Laat leeg voor merknaam uit plugininstellingen',
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'welcome_message',
                        array(
                            'label' => 'Welkomsttekst (optioneel)',
                            'type' => $controls::TEXTAREA,
                            'rows' => 3,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'show_topic_selector',
                        array(
                            'label' => 'Toon onderwerpkeuze',
                            'type' => $controls::SWITCHER,
                            'label_on' => 'Ja',
                            'label_off' => 'Nee',
                            'return_value' => 'yes',
                            'default' => 'yes',
                        )
                    );

                    $this->add_control(
                        'show_reset_button',
                        array(
                            'label' => 'Toon resetknop',
                            'type' => $controls::SWITCHER,
                            'label_on' => 'Ja',
                            'label_off' => 'Nee',
                            'return_value' => 'yes',
                            'default' => 'yes',
                        )
                    );

                    $this->end_controls_section();

                    $this->start_controls_section(
                        'octopus_style_section',
                        array(
                            'label' => 'Layout & stijl',
                            'tab' => $controls::TAB_STYLE,
                        )
                    );

                    $this->add_control(
                        'widget_height',
                        array(
                            'label' => 'Hoogte (px)',
                            'type' => $controls::SLIDER,
                            'size_units' => array('px'),
                            'range' => array(
                                'px' => array(
                                    'min' => 360,
                                    'max' => 900,
                                    'step' => 10,
                                ),
                            ),
                            'default' => array(
                                'unit' => 'px',
                                'size' => 560,
                            ),
                        )
                    );

                    $this->add_control(
                        'widget_radius',
                        array(
                            'label' => 'Afronding (px)',
                            'type' => $controls::SLIDER,
                            'size_units' => array('px'),
                            'range' => array(
                                'px' => array(
                                    'min' => 8,
                                    'max' => 28,
                                    'step' => 1,
                                ),
                            ),
                            'default' => array(
                                'unit' => 'px',
                                'size' => 16,
                            ),
                        )
                    );

                    $this->add_control(
                        'primary_color',
                        array(
                            'label' => 'Primaire kleur (optioneel)',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'header_text_color',
                        array(
                            'label' => 'Header tekstkleur (optioneel)',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'font_family',
                        array(
                            'label' => 'Lettertype familie (optioneel)',
                            'type' => $controls::TEXT,
                            'placeholder' => 'Bijv. "Roboto, Arial, sans-serif"',
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'header_font_size',
                        array(
                            'label' => 'Header tekstgrootte (px)',
                            'type' => $controls::SLIDER,
                            'size_units' => array('px'),
                            'range' => array(
                                'px' => array(
                                    'min' => 12,
                                    'max' => 24,
                                    'step' => 1,
                                ),
                            ),
                            'default' => array(
                                'unit' => 'px',
                                'size' => 16,
                            ),
                        )
                    );

                    $this->add_control(
                        'header_font_weight',
                        array(
                            'label' => 'Header tekstdikte',
                            'type' => $controls::SELECT,
                            'default' => '600',
                            'options' => array(
                                '400' => 'Normaal (400)',
                                '500' => 'Medium (500)',
                                '600' => 'Semibold (600)',
                                '700' => 'Bold (700)',
                                '800' => 'Extra Bold (800)',
                            ),
                        )
                    );

                    $this->add_control(
                        'body_font_size',
                        array(
                            'label' => 'Bericht tekstgrootte (px)',
                            'type' => $controls::SLIDER,
                            'size_units' => array('px'),
                            'range' => array(
                                'px' => array(
                                    'min' => 12,
                                    'max' => 18,
                                    'step' => 1,
                                ),
                            ),
                            'default' => array(
                                'unit' => 'px',
                                'size' => 14,
                            ),
                        )
                    );

                    $this->add_control(
                        'message_radius',
                        array(
                            'label' => 'Bericht bubble radius (px)',
                            'type' => $controls::SLIDER,
                            'size_units' => array('px'),
                            'range' => array(
                                'px' => array(
                                    'min' => 6,
                                    'max' => 24,
                                    'step' => 1,
                                ),
                            ),
                            'default' => array(
                                'unit' => 'px',
                                'size' => 12,
                            ),
                        )
                    );

                    $this->add_control(
                        'user_message_bg',
                        array(
                            'label' => 'Gebruiker bericht achtergrond',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'user_message_text',
                        array(
                            'label' => 'Gebruiker bericht tekstkleur',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'bot_message_bg',
                        array(
                            'label' => 'Bot bericht achtergrond',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'bot_message_text',
                        array(
                            'label' => 'Bot bericht tekstkleur',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'input_bg_color',
                        array(
                            'label' => 'Input achtergrondkleur',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'input_text_color',
                        array(
                            'label' => 'Input tekstkleur',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'input_border_color',
                        array(
                            'label' => 'Input randkleur',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'button_bg_color',
                        array(
                            'label' => 'Knop achtergrondkleur',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'button_text_color',
                        array(
                            'label' => 'Knop tekstkleur',
                            'type' => $controls::COLOR,
                            'default' => '',
                        )
                    );

                    $this->add_control(
                        'button_radius',
                        array(
                            'label' => 'Knop radius (px)',
                            'type' => $controls::SLIDER,
                            'size_units' => array('px'),
                            'range' => array(
                                'px' => array(
                                    'min' => 8,
                                    'max' => 26,
                                    'step' => 1,
                                ),
                            ),
                            'default' => array(
                                'unit' => 'px',
                                'size' => 20,
                            ),
                        )
                    );

                    $this->end_controls_section();
                }

                protected function render()
                {
                    if (!function_exists('octopus_ai_render_embedded_chatbot_markup')) {
                        return;
                    }

                    $settings = $this->get_settings_for_display();
                    $height = isset($settings['widget_height']['size']) ? (int) $settings['widget_height']['size'] : 560;
                    $radius = isset($settings['widget_radius']['size']) ? (int) $settings['widget_radius']['size'] : 16;
                    $header_font_size = isset($settings['header_font_size']['size']) ? (int) $settings['header_font_size']['size'] : 16;
                    $body_font_size = isset($settings['body_font_size']['size']) ? (int) $settings['body_font_size']['size'] : 14;
                    $message_radius = isset($settings['message_radius']['size']) ? (int) $settings['message_radius']['size'] : 12;
                    $button_radius = isset($settings['button_radius']['size']) ? (int) $settings['button_radius']['size'] : 20;

                    $config = array(
                        'title' => isset($settings['widget_title']) ? (string) $settings['widget_title'] : '',
                        'welcome_message' => isset($settings['welcome_message']) ? (string) $settings['welcome_message'] : '',
                        'height' => $height,
                        'radius' => $radius,
                        'show_topic_selector' => isset($settings['show_topic_selector']) && $settings['show_topic_selector'] === 'yes',
                        'show_reset_button' => isset($settings['show_reset_button']) && $settings['show_reset_button'] === 'yes',
                        'primary_color' => isset($settings['primary_color']) ? (string) $settings['primary_color'] : '',
                        'header_text_color' => isset($settings['header_text_color']) ? (string) $settings['header_text_color'] : '',
                        'font_family' => isset($settings['font_family']) ? (string) $settings['font_family'] : '',
                        'header_font_size' => $header_font_size,
                        'header_font_weight' => isset($settings['header_font_weight']) ? (string) $settings['header_font_weight'] : '600',
                        'body_font_size' => $body_font_size,
                        'message_radius' => $message_radius,
                        'user_message_bg' => isset($settings['user_message_bg']) ? (string) $settings['user_message_bg'] : '',
                        'user_message_text' => isset($settings['user_message_text']) ? (string) $settings['user_message_text'] : '',
                        'bot_message_bg' => isset($settings['bot_message_bg']) ? (string) $settings['bot_message_bg'] : '',
                        'bot_message_text' => isset($settings['bot_message_text']) ? (string) $settings['bot_message_text'] : '',
                        'input_bg_color' => isset($settings['input_bg_color']) ? (string) $settings['input_bg_color'] : '',
                        'input_text_color' => isset($settings['input_text_color']) ? (string) $settings['input_text_color'] : '',
                        'input_border_color' => isset($settings['input_border_color']) ? (string) $settings['input_border_color'] : '',
                        'button_bg_color' => isset($settings['button_bg_color']) ? (string) $settings['button_bg_color'] : '',
                        'button_text_color' => isset($settings['button_text_color']) ? (string) $settings['button_text_color'] : '',
                        'button_radius' => $button_radius,
                    );

                    $output = octopus_ai_render_embedded_chatbot_markup($config);

                    if ($output === '') {
                        if (
                            class_exists('\\Elementor\\Plugin') &&
                            isset(\Elementor\Plugin::$instance->editor) &&
                            \Elementor\Plugin::$instance->editor->is_edit_mode()
                        ) {
                            echo '<div style="padding:12px;border:1px solid #ccd0d4;background:#f6f7f7;">';
                            echo 'Zet in Octopus AI Chatbot instellingen de plaatsing op "Elementor widget op pagina".';
                            echo '</div>';
                        }
                        return;
                    }

                    echo $output;
                }
            }
        }

        $widgets_manager->register(new \Octopus_AI_Elementor_Chatbot_Widget());
    }

    add_action('elementor/widgets/register', 'octopus_ai_register_elementor_widget');
}
