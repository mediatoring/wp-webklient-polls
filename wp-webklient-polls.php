<?php
/**
 * Plugin Name: WP Webklient Polls
 * Description: Ankety jako vlastní post type + shortcode [webklient_poll id="123" results="0|1"]. AJAX hlasování, responzivní zobrazení a pruhy s procenty.
 * Version: 1.3.0
 * Author: webklient.cz
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class WPWebklient_Polls {
    const CPT = 'wpp_poll';
    const META_OPTIONS = '_wpp_options';
    const META_VOTES = '_wpp_votes';
    const META_MULTI = '_wpp_allow_multiple';
    const META_ENDS = '_wpp_ends_at_ts';
    const META_SHOW_BEFORE = '_wpp_show_results_before';
    const COOKIE_PREFIX = 'wpp_voted_';

    private static $instance = null;
    private $styles_printed = false;
    private $scripts_printed = false;

    public static function instance() {
        return self::$instance ?? (self::$instance = new self());
    }

    private function __construct() {
        add_action('init', [$this, 'register_cpt']);
        add_action('add_meta_boxes', [$this, 'add_metaboxes']);
        add_action('save_post', [$this, 'save_meta'], 10, 2);

        add_filter('manage_edit-' . self::CPT . '_columns', [$this, 'admin_cols']);
        add_action('manage_' . self::CPT . '_posts_custom_column', [$this, 'admin_cols_content'], 10, 2);

        add_shortcode('webklient_poll', [$this, 'shortcode']);

        add_action('wp_ajax_wpp_vote', [$this, 'ajax_vote']);
        add_action('wp_ajax_nopriv_wpp_vote', [$this, 'ajax_vote']);

        register_activation_hook(__FILE__, [__CLASS__, 'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__, 'deactivate']);
    }

    public static function activate() {
        register_post_type(self::CPT, [
            'labels' => ['name' => 'Ankety', 'singular_name' => 'Anketa'],
            'public' => true,
            'show_in_rest' => true,
            'supports' => ['title', 'editor']
        ]);
        flush_rewrite_rules();
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels' => [
                'name' => 'Ankety',
                'singular_name' => 'Anketa',
                'menu_name' => 'Ankety',
                'add_new' => 'Přidat novou',
                'add_new_item' => 'Přidat anketu',
                'new_item' => 'Nová anketa',
                'edit_item' => 'Upravit anketu',
                'view_item' => 'Zobrazit anketu',
                'all_items' => 'Všechny ankety',
                'search_items' => 'Hledat anketu',
                'not_found' => 'Nenalezeno'
            ],
            'public' => true,
            'show_in_rest' => true,
            'supports' => ['title', 'editor'],
            'menu_icon' => 'dashicons-chart-bar',
            'has_archive' => false,
            'rewrite' => ['slug' => 'anketa'],
        ]);
    }

    private function print_styles() {
        if ($this->styles_printed) return;
        $this->styles_printed = true;
        ?>
        <style id="wpp-styles">
        .wpp {
            background: #ffffff;
            color: #1f2937;
            font-family: system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
            padding: 24px;
            margin: 20px 0;
            max-width: 100%;
            box-sizing: border-box;
        }
        .wpp__head { margin-bottom: 16px; }
        .wpp__title {
            font-weight: 700;
            font-size: 22px;
            line-height: 1.3;
            color: #1f2937;
            margin: 0;
        }
        .wpp__desc {
            font-size: 15px;
            line-height: 1.5;
            color: #6b7280;
            margin: 12px 0 0 0;
        }
        .wpp__notice, .wpp__expired, .wpp__preview-notice {
            font-size: 14px;
            color: #6b7280;
            margin: 12px 0;
            padding: 10px 12px;
            border-radius: 8px;
            background: #fffbeb;
            border: 1px solid #fde68a;
        }
        .wpp__expired {
            background: #fef2f2;
            border-color: #fca5a5;
            color: #ef4444;
        }
        .wpp__notice {
            background: #ecfdf5;
            border-color: #a7f3d0;
            color: #10b981;
        }
        .wpp__preview-notice {
            font-weight: 600;
            background: #f3f4f6;
            border-color: #e5e7eb;
            color: #1f2937;
            margin-top: 20px;
            margin-bottom: 12px;
        }
        .wpp-error {
            background: #F03741;
            color: #ef4444;
            border: 1px solid #fca5a5;
            border-radius: 8px;
            padding: 12px 16px;
            margin: 16px 0;
            font-size: 14px;
        }
        .wpp__form { margin: 16px 0; }
        .wpp__options {
            display: grid;
            gap: 12px;
            margin-bottom: 20px;
        }
        .wpp__option {
            position: relative;
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 16px;
            border: 2px solid #e5e7eb;
            border-radius: 10px;
            background: #ffffff;
            cursor: pointer;
            transition: all 0.2s ease;
            min-height: 24px;
        }
        .wpp__option:hover {
            border-color: #3b82f6;
            background: #f8fafc;
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .wpp__input {
            position: absolute;
            opacity: 0;
            width: 1px;
            height: 1px;
            overflow: hidden;
            clip: rect(0, 0, 0, 0);
            white-space: nowrap;
            border: 0;
        }
        .wpp__label {
            position: relative;
            flex: 1;
            font-size: 15px;
            line-height: 1.4;
            padding-left: 32px;
            cursor: pointer;
            word-break: break-word;
        }
        .wpp__label::before, .wpp__label::after {
            content: '';
            position: absolute;
            left: 0;
            top: 2px;
        }
        .wpp__label::before {
            width: 20px;
            height: 20px;
            border: 2px solid #e5e7eb;
            background: #ffffff;
            border-radius: 50%;
            transition: all 0.2s ease;
        }
        .wpp--multi .wpp__label::before { border-radius: 6px; }
        .wpp__label::after {
            width: 12px;
            height: 12px;
            left: 4px;
            top: 6px;
            background: #3b82f6;
            border-radius: 50%;
            opacity: 0;
            transform: scale(0.5);
            transition: all 0.2s ease;
        }
        .wpp--multi .wpp__label::after {
            width: 8px;
            height: 4px;
            left: 6px;
            top: 8px;
            background: transparent;
            border: 2px solid #3b82f6;
            border-top: none;
            border-right: none;
            border-radius: 0;
            transform: rotate(-45deg) scale(0.5);
        }
        .wpp__input:checked + .wpp__label::before {
            border-color: #3b82f6;
            background: #eff6ff;
        }
        .wpp__input:checked + .wpp__label::after {
            opacity: 1;
            transform: scale(1);
        }
        .wpp--multi .wpp__input:checked + .wpp__label::after {
            transform: rotate(-45deg) scale(1);
        }
        .wpp__actions { margin-top: 20px; }
        .wpp__btn {
            appearance: none;
            border: 0;
            background: #3b82f6;
            color: #ffffff;
            border-radius: 8px;
            padding: 12px 24px;
            font-weight: 600;
            font-size: 15px;
            font-family: inherit;
            cursor: pointer;
            transition: all 0.2s ease;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
            min-height: 44px;
        }
        .wpp__btn:hover:not(:disabled) {
            background: #2563eb;
            transform: translateY(-1px);
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
        .wpp__btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        .wpp__results { margin-top: 20px; }
        .wpp__results--collapsed {
            opacity: 0.8;
            margin-top: 16px;
        }
        .wpp__bar { margin: 16px 0; }
        .wpp__bar-label {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .wpp__bar-text {
            flex: 1;
            font-weight: 500;
            color: #1f2937;
            overflow: hidden;
            text-overflow: ellipsis;
            word-break: break-word;
        }
        .wpp__bar-val {
            white-space: nowrap;
            color: #6b7280;
            font-weight: 600;
            font-size: 13px;
        }
        .wpp__bar-track {
            position: relative;
            height: 24px;
            background: #f1f5f9;
            border-radius: 12px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            padding-left:10px;
        }
        .wpp__bar-fill {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: #F03741;
            border-radius: 12px;
            transition: width 0.6s ease;
            min-width: 20px;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            padding: 0 8px;
            box-sizing: border-box;
        }
        .wpp__bar-num {
            font-weight: 700;
            font-size: 12px;
            color: white;
            text-shadow: 0 1px 2px rgba(0, 0, 0, 0.3);
            z-index: 1;
            position: relative;
            white-space: nowrap;
            padding-left: 10px
        }
        .wpp__total {
            margin-top: 16px;
            padding: 12px 16px;
            background: #f8fafc;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            font-size: 14px;
            color: #6b7280;
            text-align: center;
        }
        .wpp__total strong {
            color: #1f2937;
            font-weight: 700;
        }
        @media (max-width: 640px) {
            .wpp {
                padding: 16px;
                margin: 16px 0;
            }
            .wpp__title { font-size: 18px; }
            .wpp__option { padding: 12px; }
            .wpp__btn {
                width: 100%;
                padding: 14px 20px;
            }
            .wpp__bar-label {
                flex-direction: column;
                align-items: flex-start;
                gap: 4px;
            }
            .wpp__bar-val { align-self: flex-end; }
        }
        </style>
        <?php
    }

    private function print_scripts() {
        if ($this->scripts_printed) return;
        $this->scripts_printed = true;
        ?>
        <script id="wpp-scripts">
        (function() {
            'use strict';
            
            function $(sel, ctx) { return (ctx || document).querySelector(sel); }
            function $$(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }
            
            function getCookieName(pollId) {
                return 'wpp_voted_' + pollId;
            }
            
            function setCookie(name, value, days) {
                var expires = '';
                if (days) {
                    var date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    expires = '; expires=' + date.toUTCString();
                }
                document.cookie = name + '=' + (value || '') + expires + '; path=/';
            }
            
            function serializeChoices(form) {
                var inputs = $$('.wpp__input:checked', form);
                return inputs.map(function(input) {
                    return parseInt(input.value, 10);
                }).filter(function(val) {
                    return !isNaN(val);
                });
            }
            
            function showMessage(container, message, type) {
                var messageEl = document.createElement('div');
                messageEl.className = 'wpp__notice wpp__notice--' + (type || 'success');
                messageEl.textContent = message;
                
                var existingMessage = $('.wpp__notice', container);
                if (existingMessage) {
                    existingMessage.remove();
                }
                
                var head = $('.wpp__head', container);
                if (head && head.nextSibling) {
                    container.insertBefore(messageEl, head.nextSibling);
                } else {
                    container.appendChild(messageEl);
                }
            }
            
            function disableForm(form, disabled) {
                var button = $('.wpp__btn', form);
                var inputs = $$('.wpp__input', form);
                
                if (button) {
                    button.disabled = disabled;
                    button.textContent = disabled ? 'Odesílám...' : 'Hlasovat';
                }
                
                inputs.forEach(function(input) {
                    input.disabled = disabled;
                });
                
                form.style.opacity = disabled ? '0.6' : '';
            }
            
            function replaceWithResults(container, resultsHtml, message) {
                var form = $('.wpp__form', container);
                if (form) {
                    form.remove();
                }
                
                if (message) {
                    showMessage(container, message, 'success');
                }
                
                var tempDiv = document.createElement('div');
                tempDiv.innerHTML = resultsHtml;
                var newResults = tempDiv.firstElementChild;
                
                var existingResults = $('.wpp__results', container);
                if (existingResults) {
                    existingResults.replaceWith(newResults);
                } else {
                    container.appendChild(newResults);
                }
            }
            
            function handleSubmit(event) {
                event.preventDefault();
                
                var form = event.currentTarget;
                var container = form.closest('.wpp');
                var pollId = parseInt(container.getAttribute('data-poll'), 10);
                var nonce = form.getAttribute('data-nonce');
                
                var choices = serializeChoices(form);
                if (choices.length === 0) {
                    showMessage(container, 'Prosím vyberte alespoň jednu možnost.', 'error');
                    return;
                }
                
                disableForm(form, true);
                
                var xhr = new XMLHttpRequest();
                var formData = new FormData();
                
                formData.append('action', 'wpp_vote');
                formData.append('poll', pollId);
                formData.append('nonce', nonce);
                
                choices.forEach(function(choice) {
                    formData.append('choices[]', choice);
                });
                
                xhr.open('POST', '<?php echo admin_url('admin-ajax.php'); ?>', true);
                
                xhr.onreadystatechange = function() {
                    if (xhr.readyState === 4) {
                        disableForm(form, false);
                        
                        try {
                            var response = JSON.parse(xhr.responseText || '{}');
                            
                            if (xhr.status === 200 && response.success) {
                                setCookie(getCookieName(pollId), '1', 365);
                                replaceWithResults(
                                    container, 
                                    response.data.results_html,
                                    response.data.message || 'Váš hlas byl úspěšně zaznamenán!'
                                );
                            } else {
                                var errorMsg = (response.data && response.data.msg) || 'Nastala chyba při hlasování.';
                                showMessage(container, errorMsg, 'error');
                            }
                        } catch (parseError) {
                            showMessage(container, 'Nastala chyba při komunikaci se serverem.', 'error');
                        }
                    }
                };
                
                xhr.onerror = function() {
                    disableForm(form, false);
                    showMessage(container, 'Nastala chyba sítě. Zkuste to prosím znovu.', 'error');
                };
                
                xhr.send(formData);
            }
            
            function initializePolls() {
                $$('.wpp__form').forEach(function(form) {
                    form.removeEventListener('submit', handleSubmit);
                    form.addEventListener('submit', handleSubmit);
                });
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initializePolls);
            } else {
                initializePolls();
            }
            
            window.WPPollsInit = initializePolls;
        })();
        </script>
        <?php
    }

    public function add_metaboxes() {
        add_meta_box('wpp_poll_fields', 'Nastavení ankety', [$this, 'mb_fields'], self::CPT, 'normal', 'high');
        add_meta_box('wpp_poll_shortcode', 'Shortcode', [$this, 'mb_shortcode'], self::CPT, 'side', 'high');
        add_meta_box('wpp_poll_results', 'Výsledky', [$this, 'mb_results'], self::CPT, 'side', 'default');
    }

    public function mb_fields($post) {
        wp_nonce_field('wpp_save', 'wpp_nonce');
        $opts = (array) get_post_meta($post->ID, self::META_OPTIONS, true);
        $votes = (array) get_post_meta($post->ID, self::META_VOTES, true);
        $multi = (int) get_post_meta($post->ID, self::META_MULTI, true);
        $ends = (int) get_post_meta($post->ID, self::META_ENDS, true);
        $showb = (int) get_post_meta($post->ID, self::META_SHOW_BEFORE, true);

        $opts = array_values(array_filter(array_map('trim', $opts), fn($v) => $v !== ''));
        if (count($votes) !== count($opts)) {
            $votes = array_fill(0, max(0, count($opts)), 0);
        }

        $opts_text = implode("\n", $opts);
        $local = '';
        if ($ends > 0) {
            $dt = new DateTime('@' . $ends);
            $dt->setTimezone(wp_timezone());
            $local = $dt->format('Y-m-d\TH:i');
        }
        ?>
        <style>
        .wpp-field { margin-bottom: 15px; }
        .wpp-field label { display: block; margin-bottom: 5px; font-weight: 600; }
        .wpp-field textarea { width: 100%; min-height: 120px; }
        .wpp-field input[type="datetime-local"] { width: 100%; }
        .wpp-help { color: #666; font-size: 12px; margin-top: 4px; }
        </style>
        <div class="wpp-field">
            <label for="wpp_options"><strong>Možnosti hlasování</strong> <small>(jedna na řádek)</small></label>
            <textarea id="wpp_options" name="wpp_options" class="large-text"><?php echo esc_textarea($opts_text); ?></textarea>
        </div>
        <div class="wpp-field">
            <label>
                <input type="checkbox" name="wpp_allow_multiple" value="1" <?php checked($multi, 1); ?>>
                Povolit více odpovědí
            </label>
        </div>
        <div class="wpp-field">
            <label for="wpp_ends"><strong>Konec hlasování</strong></label>
            <input type="datetime-local" id="wpp_ends" name="wpp_ends" value="<?php echo esc_attr($local); ?>">
            <div class="wpp-help">Po uplynutí se anketa automaticky ukončí.</div>
        </div>
        <div class="wpp-field">
            <label>
                <input type="checkbox" name="wpp_show_before" value="1" <?php checked($showb, 1); ?>>
                Zobrazit průběžné výsledky i před hlasováním
            </label>
        </div>
        <?php
    }

    public function mb_shortcode($post) {
        $shortcode_basic = '[webklient_poll id="' . $post->ID . '"]';
        $shortcode_results = '[webklient_poll id="' . $post->ID . '" results="1"]';
        echo '<div style="margin-bottom: 10px;"><strong>Základní shortcode:</strong></div>';
        echo '<input type="text" readonly value="' . esc_attr($shortcode_basic) . '" style="width:100%; margin-bottom: 10px;" onclick="this.select();">';
        echo '<div style="margin-bottom: 10px;"><strong>Pouze výsledky:</strong></div>';
        echo '<input type="text" readonly value="' . esc_attr($shortcode_results) . '" style="width:100%;" onclick="this.select();">';
        echo '<div style="margin-top: 10px; color: #666; font-size: 12px;">Kliknutím na shortcode ho zkopírujete.</div>';
    }

    public function mb_results($post) {
        wp_nonce_field('wpp_results', 'wpp_results_nonce');
        $data = $this->poll_data($post->ID);
        
        if (!$data || empty($data['options'])) {
            echo '<em>Zatím nejsou definované žádné možnosti hlasování.</em>';
            return;
        }

        $total = array_sum($data['votes']);
        echo '<div style="font-size: 14px; margin-bottom: 10px; padding: 8px; background: #f0f0f1; border-radius: 4px;">';
        echo '<strong>Celkem hlasů: ' . intval($total) . '</strong>';
        echo '</div>';

        if ($total > 0) {
            echo '<table style="width: 100%; font-size: 12px; border-collapse: collapse; margin-bottom: 10px;">';
            echo '<thead><tr style="background: #f9f9f9;"><th style="padding: 6px; text-align: left; border: 1px solid #ddd;">Možnost</th><th style="padding: 6px; text-align: right; border: 1px solid #ddd;">%</th><th style="padding: 6px; text-align: right; border: 1px solid #ddd;">Hlasy</th></tr></thead>';
            echo '<tbody>';
            
            foreach ($data['options'] as $i => $label) {
                $cnt = intval($data['votes'][$i] ?? 0);
                $pct = $total ? round($cnt * 100 / $total, 1) : 0;
                echo '<tr>';
                echo '<td style="padding: 6px; border: 1px solid #ddd;">' . esc_html($label) . '</td>';
                echo '<td style="padding: 6px; text-align: right; border: 1px solid #ddd; font-weight: 600;">' . $pct . '%</td>';
                echo '<td style="padding: 6px; text-align: right; border: 1px solid #ddd;">' . $cnt . '</td>';
                echo '</tr>';
            }
            
            echo '</tbody></table>';
        } else {
            echo '<div style="color: #666; font-style: italic; margin-bottom: 10px;">Zatím neproběhlo žádné hlasování.</div>';
        }

        echo '<div style="margin-top: 10px;">';
        echo '<button class="button button-secondary" type="submit" name="wpp_reset_votes" value="1" onclick="return confirm(\'Opravdu chcete resetovat všechny hlasy?\')">Resetovat hlasy</button>';
        echo '</div>';

        // Náhled ankety
        if (!empty($data['options'])) {
            echo '<div style="margin-top: 15px; padding-top: 10px; border-top: 1px solid #ddd;">';
            echo '<strong style="display: block; margin-bottom: 8px;">Náhled ankety:</strong>';
            echo '<div style="background: #fff; border: 1px solid #ddd; padding: 10px; border-radius: 4px; font-size: 12px;">';
            echo $this->render_poll_preview($data);
            echo '</div>';
            echo '</div>';
        }
    }

    private function render_poll_preview($data) {
        $input_type = $data['multi'] ? 'checkbox' : 'radio';
        ob_start();
        ?>
        <div style="font-family: system-ui, -apple-system, sans-serif;">
            <div style="font-weight: 700; font-size: 14px; margin-bottom: 8px;"><?php echo esc_html($data['title']); ?></div>
            <?php foreach ($data['options'] as $i => $label): ?>
                <div style="margin: 6px 0; padding: 6px; border: 1px solid #e0e0e0; border-radius: 4px;">
                    <input type="<?php echo $input_type; ?>" disabled style="margin-right: 8px;">
                    <?php echo esc_html($label); ?>
                </div>
            <?php endforeach; ?>
            <div style="margin-top: 8px;">
                <button disabled style="background: #3b82f6; color: white; border: none; padding: 6px 12px; border-radius: 4px; font-size: 12px;">Hlasovat</button>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    public function save_meta($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (!isset($_POST['wpp_nonce']) || !wp_verify_nonce($_POST['wpp_nonce'], 'wpp_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post', $post_id)) return;

        // Uložení možností
        $opts_txt = isset($_POST['wpp_options']) ? wp_unslash($_POST['wpp_options']) : '';
        $opts = array_values(array_filter(array_map('trim', explode("\n", $opts_txt)), fn($v) => $v !== ''));
        update_post_meta($post_id, self::META_OPTIONS, $opts);

        // Úprava hlasů podle počtu možností
        $cur_votes = (array) get_post_meta($post_id, self::META_VOTES, true);
        if (count($cur_votes) !== count($opts)) {
            $cur_votes = array_fill(0, count($opts), 0);
        }
        update_post_meta($post_id, self::META_VOTES, array_map('intval', $cur_votes));

        // Více odpovědí
        update_post_meta($post_id, self::META_MULTI, isset($_POST['wpp_allow_multiple']) ? 1 : 0);

        // Konec hlasování
        if (!empty($_POST['wpp_ends'])) {
            try {
                $dt = new DateTime(sanitize_text_field($_POST['wpp_ends']), wp_timezone());
                $dt->setTimezone(new DateTimeZone('UTC'));
                update_post_meta($post_id, self::META_ENDS, $dt->getTimestamp());
            } catch (Exception $e) {
                delete_post_meta($post_id, self::META_ENDS);
            }
        } else {
            delete_post_meta($post_id, self::META_ENDS);
        }

        // Zobrazování výsledků
        update_post_meta($post_id, self::META_SHOW_BEFORE, isset($_POST['wpp_show_before']) ? 1 : 0);

        // Reset hlasů
        if (isset($_POST['wpp_results_nonce']) && wp_verify_nonce($_POST['wpp_results_nonce'], 'wpp_results') && isset($_POST['wpp_reset_votes'])) {
            $opts_now = (array) get_post_meta($post_id, self::META_OPTIONS, true);
            $opts_now = array_values(array_filter(array_map('trim', $opts_now), fn($v) => $v !== ''));
            update_post_meta($post_id, self::META_VOTES, array_fill(0, count($opts_now), 0));
        }
    }

    public function admin_cols($cols) {
        $new_cols = [];
        foreach ($cols as $k => $v) {
            $new_cols[$k] = $v;
            if ($k === 'title') {
                $new_cols['wpp_votes'] = 'Celkem hlasů';
                $new_cols['wpp_options'] = 'Možnosti';
            }
        }
        $new_cols['wpp_status'] = 'Stav';
        $new_cols['wpp_ends'] = 'Konec';
        return $new_cols;
    }

    public function admin_cols_content($col, $post_id) {
        switch ($col) {
            case 'wpp_votes':
                $votes = (array) get_post_meta($post_id, self::META_VOTES, true);
                $total = array_sum(array_map('intval', $votes));
                echo '<strong>' . $total . '</strong>';
                break;

            case 'wpp_options':
                $opts = (array) get_post_meta($post_id, self::META_OPTIONS, true);
                $opts = array_filter(array_map('trim', $opts));
                echo count($opts) . ' možností';
                break;

            case 'wpp_status':
                $ends = (int) get_post_meta($post_id, self::META_ENDS, true);
                if ($ends > 0 && time() >= $ends) {
                    echo '<span style="color: #d63384;">Ukončeno</span>';
                } else {
                    echo '<span style="color: #198754;">Aktivní</span>';
                }
                break;

            case 'wpp_ends':
                $ts = (int) get_post_meta($post_id, self::META_ENDS, true);
                if ($ts > 0) {
                    $dt = new DateTime('@' . $ts);
                    $dt->setTimezone(wp_timezone());
                    echo esc_html($dt->format('j.n.Y H:i'));
                } else {
                    echo '<span style="color: #999;">nenastaveno</span>';
                }
                break;
        }
    }

    private function poll_data($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type !== self::CPT) return null;

        $opts = (array) get_post_meta($post_id, self::META_OPTIONS, true);
        $votes = (array) get_post_meta($post_id, self::META_VOTES, true);
        $multi = (int) get_post_meta($post_id, self::META_MULTI, true);
        $ends = (int) get_post_meta($post_id, self::META_ENDS, true);
        $showb = (int) get_post_meta($post_id, self::META_SHOW_BEFORE, true);

        $opts = array_values(array_filter(array_map('trim', $opts), fn($v) => $v !== ''));
        if (count($votes) !== count($opts)) {
            $votes = array_fill(0, count($opts), 0);
        }

        $expired = ($ends > 0 && time() >= $ends);

        return [
            'id' => $post_id,
            'title' => get_the_title($post_id),
            'desc' => apply_filters('the_content', $post->post_content),
            'options' => $opts,
            'votes' => array_map('intval', $votes),
            'multi' => $multi ? true : false,
            'ends' => $ends,
            'expired' => $expired,
            'show_before' => $showb ? true : false,
            'permalink' => get_permalink($post_id),
        ];
    }

    public function shortcode($atts = []) {
        $a = shortcode_atts(['id' => 0, 'results' => 0], $atts, 'webklient_poll');
        $id = intval($a['id']);
        $force_results = intval($a['results']) === 1;

        if (!$id) return '<div class="wpp-error">Chyba: Není zadáno ID ankety.</div>';

        $data = $this->poll_data($id);
        if (!$data) return '<div class="wpp-error">Chyba: Anketa nebyla nalezena.</div>';

        if (empty($data['options'])) {
            return '<div class="wpp-error">Chyba: Anketa nemá definované možnosti hlasování.</div>';
        }

        // Načti styly a skripty inline
        $this->print_styles();
        $this->print_scripts();

        $input_type = $data['multi'] ? 'checkbox' : 'radio';
        $voted = $this->has_voted($id);

        ob_start();
        ?>
        <div class="wpp wpp--<?php echo $data['multi'] ? 'multi' : 'single'; ?>" data-poll="<?php echo esc_attr($id); ?>">
            <div class="wpp__head">
                <div class="wpp__title"><?php echo esc_html($data['title']); ?></div>
            </div>
            
            <?php if (!empty($data['desc'])): ?>
                <div class="wpp__desc"><?php echo $data['desc']; ?></div>
            <?php endif; ?>

            <?php if ($data['expired'] || $force_results): ?>
                <?php if ($data['expired']): ?>
                    <div class="wpp__expired">Hlasování skončilo.</div>
                <?php endif; ?>
                <?php echo $this->render_results($data); ?>
                
            <?php elseif ($voted): ?>
                <div class="wpp__notice">Již jste hlasoval/a. Děkujeme!</div>
                <?php echo $this->render_results($data); ?>
                
            <?php else: ?>
                <form class="wpp__form" data-nonce="<?php echo esc_attr(wp_create_nonce('wpp_vote_' . $id)); ?>">
                    <div class="wpp__options">
                        <?php foreach ($data['options'] as $i => $label): ?>
                            <label class="wpp__option">
                                <input class="wpp__input" type="<?php echo $input_type; ?>" 
                                       name="wpp_choice_<?php echo esc_attr($id); ?>[]" 
                                       value="<?php echo esc_attr($i); ?>">
                                <span class="wpp__label"><?php echo esc_html($label); ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="wpp__actions">
                        <button type="submit" class="wpp__btn">Hlasovat</button>
                    </div>
                </form>
                
                <?php if ($data['show_before']): ?>
                    <div class="wpp__preview-notice">Průběžné výsledky:</div>
                    <?php echo $this->render_results($data, true); ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_results($data, $collapsed = false) {
        $total = array_sum($data['votes']);
        if ($total < 1) $total = 1;

        ob_start();
        ?>
        <div class="wpp__results<?php echo $collapsed ? ' wpp__results--collapsed' : ''; ?>" aria-live="polite">
            <?php foreach ($data['options'] as $i => $label): 
                $count = intval($data['votes'][$i] ?? 0);
                $pct = round($count * 100 / $total, 1);
                $label_esc = esc_html($label);
                $val_txt = $pct . '% (' . $count . ')';
            ?>
                <div class="wpp__bar" role="group" aria-label="<?php echo $label_esc; ?>">
                    <div class="wpp__bar-label">
                        <span class="wpp__bar-text"><?php echo $label_esc; ?></span>
                        <span class="wpp__bar-val"><?php echo $val_txt; ?></span>
                    </div>
                    <div class="wpp__bar-track" aria-hidden="true">
                        <div class="wpp__bar-fill" style="width: <?php echo $pct; ?>%">
                            <span class="wpp__bar-num">&nbsp;<?php echo $pct; ?>%</span>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
            
            <?php if (!$collapsed && array_sum($data['votes']) > 0): ?>
                <div class="wpp__total">
                    Celkem hlasů: <strong><?php echo array_sum($data['votes']); ?></strong>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function has_voted($poll_id) {
        $cookie = self::COOKIE_PREFIX . $poll_id;
        if (!empty($_COOKIE[$cookie])) return true;

        $ip = $this->client_ip();
        $key = 'wpp_lock_' . md5($poll_id . '|' . $ip . '|' . wp_salt('auth'));
        return (bool) get_transient($key);
    }

    public function ajax_vote() {
        $poll_id = isset($_POST['poll']) ? intval($_POST['poll']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';

        if (!$poll_id || !wp_verify_nonce($nonce, 'wpp_vote_' . $poll_id)) {
            wp_send_json_error(['msg' => 'Neplatná žádost'], 400);
        }

        $data = $this->poll_data($poll_id);
        if (!$data) {
            wp_send_json_error(['msg' => 'Anketa nenalezena'], 404);
        }

        if ($data['expired']) {
            wp_send_json_error(['msg' => 'Hlasování již skončilo'], 400);
        }

        if ($this->has_voted($poll_id)) {
            wp_send_json_error(['msg' => 'Již jste hlasoval/a'], 400);
        }

        $choices = isset($_POST['choices']) ? (array) $_POST['choices'] : [];
        $choices = array_values(array_unique(array_map('intval', $choices)));

        if (empty($choices)) {
            wp_send_json_error(['msg' => 'Prosím vyberte alespoň jednu možnost'], 400);
        }

        if (!$data['multi'] && count($choices) > 1) {
            $choices = [reset($choices)];
        }

        $max_index = count($data['options']) - 1;
        foreach ($choices as $i) {
            if ($i < 0 || $i > $max_index) {
                wp_send_json_error(['msg' => 'Neplatná volba'], 400);
            }
        }

        // Uložení hlasů
        $votes = (array) get_post_meta($poll_id, self::META_VOTES, true);
        if (count($votes) !== count($data['options'])) {
            $votes = array_fill(0, count($data['options']), 0);
        }

        foreach ($choices as $i) {
            $votes[$i] = intval($votes[$i]) + 1;
        }

        update_post_meta($poll_id, self::META_VOTES, array_map('intval', $votes));

        // Označení jako hlasováno
        setcookie(self::COOKIE_PREFIX . $poll_id, '1', time() + 365 * 24 * 3600, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true);

        $ip = $this->client_ip();
        set_transient('wpp_lock_' . md5($poll_id . '|' . $ip . '|' . wp_salt('auth')), 1, 365 * DAY_IN_SECONDS);

        // Vrácení nových výsledků
        $data = $this->poll_data($poll_id);
        $html = $this->render_results($data);

        wp_send_json_success([
            'total' => array_sum($data['votes']),
            'results_html' => $html,
            'message' => 'Váš hlas byl úspěšně zaznamenán!'
        ]);
    }

    private function client_ip() {
        foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) {
                $raw = explode(',', $_SERVER[$k]);
                return trim($raw[0]);
            }
        }
        return '0.0.0.0';
    }
}

// Inicializace pluginu
add_action('plugins_loaded', function() {
    WPWebklient_Polls::instance();
});