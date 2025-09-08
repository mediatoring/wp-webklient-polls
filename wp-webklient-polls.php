<?php
/**
 * Plugin Name: WP Webklient Polls
 * Description: Ankety jako vlastní post type + shortcode [webklient_poll id="123"]. AJAX hlasování, responzivní zobrazení i výsledky.
 * Version: 1.0.0
 * Author: webklient.cz
 * License: GPLv2 or later
 */

if (!defined('ABSPATH')) exit;

final class WPWebklient_Polls {
    const CPT = 'wpp_poll';
    const META_OPTIONS = '_wpp_options';          // array<string>
    const META_VOTES = '_wpp_votes';              // array<int>
    const META_MULTI = '_wpp_allow_multiple';     // 0/1
    const META_ENDS  = '_wpp_ends_at_ts';         // int (UTC)
    const META_SHOW_BEFORE = '_wpp_show_results_before'; // 0/1
    const COOKIE_PREFIX = 'wpp_voted_';

    private static $instance = null;
    public static function instance(){ return self::$instance ?? (self::$instance = new self()); }

    private function __construct() {
        add_action('init', [$this,'register_cpt']);
        add_action('add_meta_boxes', [$this,'add_metaboxes']);
        add_action('save_post', [$this,'save_meta'], 10, 2);
        add_filter('manage_edit-'.self::CPT.'_columns', [$this,'admin_cols']);
        add_action('manage_'.self::CPT.'_posts_custom_column', [$this,'admin_cols_content'], 10, 2);

        add_shortcode('webklient_poll', [$this,'shortcode']);

        add_action('wp_ajax_wpp_vote', [$this,'ajax_vote']);
        add_action('wp_ajax_nopriv_wpp_vote', [$this,'ajax_vote']);

        register_activation_hook(__FILE__, [__CLASS__,'activate']);
        register_deactivation_hook(__FILE__, [__CLASS__,'deactivate']);
    }

    public static function activate() {
        $labels=['name'=>'Ankety','singular_name'=>'Anketa','menu_name'=>'Ankety'];
        register_post_type(self::CPT, ['labels'=>$labels,'public'=>true,'show_in_rest'=>true,'supports'=>['title','editor']]);
        flush_rewrite_rules();
    }
    public static function deactivate(){ flush_rewrite_rules(); }

    public function register_cpt() {
        register_post_type(self::CPT, [
            'labels'=>[
                'name'=>'Ankety','singular_name'=>'Anketa','menu_name'=>'Ankety',
                'add_new'=>'Přidat novou','add_new_item'=>'Přidat anketu','new_item'=>'Nová anketa',
                'edit_item'=>'Upravit anketu','view_item'=>'Zobrazit anketu','all_items'=>'Všechny ankety',
                'search_items'=>'Hledat anketu','not_found'=>'Nenalezeno'
            ],
            'public'=>true,
            'show_in_rest'=>true,
            'supports'=>['title','editor'],
            'menu_icon'=>'dashicons-chart-bar',
            'has_archive'=>false,
            'rewrite'=>['slug'=>'anketa'],
        ]);
    }

    public function add_metaboxes() {
        add_meta_box('wpp_poll_fields','Nastavení ankety',[$this,'mb_fields'], self::CPT, 'normal', 'high');
        add_meta_box('wpp_poll_shortcode','Shortcode',[$this,'mb_shortcode'], self::CPT, 'side', 'high');
    }

    public function mb_fields($post) {
        wp_nonce_field('wpp_save','wpp_nonce');
        $opts = (array) get_post_meta($post->ID, self::META_OPTIONS, true);
        $votes = (array) get_post_meta($post->ID, self::META_VOTES, true);
        $multi = (int) get_post_meta($post->ID, self::META_MULTI, true);
        $ends  = (int) get_post_meta($post->ID, self::META_ENDS, true);
        $showb = (int) get_post_meta($post->ID, self::META_SHOW_BEFORE, true);

        // sjednocení délek
        $opts = array_values(array_filter(array_map('trim', $opts), fn($v)=>$v!==''));
        if (count($votes) !== count($opts)) $votes = array_fill(0, max(0,count($opts)), 0);

        $opts_text = implode("\n", $opts);
        $local = '';
        if ($ends > 0) { $dt=new DateTime('@'.$ends); $dt->setTimezone(wp_timezone()); $local=$dt->format('Y-m-d\TH:i'); }
        ?>
        <style>.wpp-field{margin-bottom:12px}.wpp-field label{display:block;margin-bottom:4px}.wpp-field textarea{width:100%;min-height:120px}</style>
        <div class="wpp-field">
            <label for="wpp_options"><strong>Možnosti (jedna na řádek)</strong></label>
            <textarea id="wpp_options" name="wpp_options"><?php echo esc_textarea($opts_text); ?></textarea>
        </div>
        <div class="wpp-field">
            <label><input type="checkbox" name="wpp_allow_multiple" value="1" <?php checked($multi,1); ?>> Povol více odpovědí</label>
        </div>
        <div class="wpp-field">
            <label for="wpp_ends"><strong>Konec hlasování</strong></label>
            <input type="datetime-local" id="wpp_ends" name="wpp_ends" value="<?php echo esc_attr($local); ?>">
            <div style="color:#666;font-size:12px">Po uplynutí se anketa skryje. Čas se ukládá v UTC dle časové zóny webu.</div>
        </div>
        <div class="wpp-field">
            <label><input type="checkbox" name="wpp_show_before" value="1" <?php checked($showb,1); ?>> Zobrazit průběžné výsledky i před hlasováním</label>
        </div>
        <?php
    }

    public function mb_shortcode($post) {
        $code = '[webklient_poll id="'.$post->ID.'"]';
        echo '<input type="text" readonly value="'.esc_attr($code).'" style="width:100%;">';
        echo '<div style="margin-top:6px;color:#666;font-size:12px">Vlož do stránky nebo článku.</div>';
    }

    public function save_meta($post_id, $post) {
        if ($post->post_type !== self::CPT) return;
        if (!isset($_POST['wpp_nonce']) || !wp_verify_nonce($_POST['wpp_nonce'],'wpp_save')) return;
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (!current_user_can('edit_post',$post_id)) return;

        $opts_txt = isset($_POST['wpp_options']) ? wp_unslash($_POST['wpp_options']) : '';
        $opts = array_values(array_filter(array_map('trim', explode("\n", $opts_txt)), fn($v)=>$v!==''));
        update_post_meta($post_id, self::META_OPTIONS, $opts);

        $cur_votes = (array) get_post_meta($post_id, self::META_VOTES, true);
        if (count($cur_votes) !== count($opts)) $cur_votes = array_fill(0, count($opts), 0);
        update_post_meta($post_id, self::META_VOTES, array_map('intval',$cur_votes));

        update_post_meta($post_id, self::META_MULTI, isset($_POST['wpp_allow_multiple']) ? 1 : 0);

        if (!empty($_POST['wpp_ends'])) {
            try {
                $dt = new DateTime(sanitize_text_field($_POST['wpp_ends']), wp_timezone());
                $dt->setTimezone(new DateTimeZone('UTC'));
                update_post_meta($post_id, self::META_ENDS, $dt->getTimestamp());
            } catch (Exception $e) {
                delete_post_meta($post_id, self::META_ENDS);
            }
        } else delete_post_meta($post_id, self::META_ENDS);

        update_post_meta($post_id, self::META_SHOW_BEFORE, isset($_POST['wpp_show_before']) ? 1 : 0);
    }

    public function admin_cols($cols) {
        $n=[]; foreach($cols as $k=>$v){ $n[$k]=$v; if($k==='title') $n['wpp_votes']='Hlasy'; }
        $n['wpp_ends']='Konec';
        return $n;
    }
    public function admin_cols_content($col,$post_id){
        if ($col==='wpp_votes'){
            $v = (array) get_post_meta($post_id, self::META_VOTES, true);
            echo array_sum(array_map('intval',$v));
        } elseif ($col==='wpp_ends'){
            $ts=(int)get_post_meta($post_id,self::META_ENDS,true);
            if ($ts>0){ $dt=new DateTime('@'.$ts); $dt->setTimezone(wp_timezone()); echo esc_html($dt->format('j.n.Y H:i')); }
            else echo '<span style="color:#999">nenastaveno</span>';
        }
    }

    private function poll_data($post_id) {
        $post = get_post($post_id);
        if (!$post || $post->post_type!==self::CPT || $post->post_status!=='publish') return null;
        $opts  = (array) get_post_meta($post_id, self::META_OPTIONS, true);
        $votes = (array) get_post_meta($post_id, self::META_VOTES, true);
        $multi = (int) get_post_meta($post_id, self::META_MULTI, true);
        $ends  = (int) get_post_meta($post_id, self::META_ENDS, true);
        $showb = (int) get_post_meta($post_id, self::META_SHOW_BEFORE, true);

        $opts = array_values(array_filter(array_map('trim',$opts), fn($v)=>$v!==''));
        if (count($votes)!==count($opts)) $votes = array_fill(0, count($opts), 0);

        $expired = ($ends>0 && time()>=$ends);
        return [
            'id'=>$post_id,
            'title'=>get_the_title($post_id),
            'desc'=>apply_filters('the_content',$post->post_content),
            'options'=>$opts,
            'votes'=>array_map('intval',$votes),
            'multi'=>$multi?true:false,
            'ends'=>$ends,
            'expired'=>$expired,
            'show_before'=>$showb?true:false,
            'permalink'=>get_permalink($post_id),
        ];
    }

    public function shortcode($atts=[]) {
        $a = shortcode_atts(['id'=>0], $atts, 'webklient_poll');
        $id = intval($a['id']);
        if (!$id) return '';

        $data = $this->poll_data($id);
        if (!$data) return '';

        wp_enqueue_style('wpp-css', plugins_url('assets/polls.css', __FILE__), [], '1.0.0');
        wp_register_script('wpp-js', plugins_url('assets/polls.js', __FILE__), [], '1.0.0', true);
        wp_localize_script('wpp-js','WPPoll',[
            'ajax'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('wpp_vote_'.$id),
            'cookie_prefix'=>self::COOKIE_PREFIX,
        ]);
        wp_enqueue_script('wpp-js');

        $choices_name = 'wpp_choice_'.$id.'[]';
        $input_type = $data['multi'] ? 'checkbox' : 'radio';
        $voted = $this->has_voted($id);

        ob_start();
        ?>
        <div class="wpp wpp--<?php echo $data['multi']?'multi':'single'; ?>" data-poll="<?php echo esc_attr($id); ?>">
          <div class="wpp__head">
            <div class="wpp__title"><?php echo esc_html($data['title']); ?></div>
          </div>
          <?php if (!empty($data['desc'])): ?>
            <div class="wpp__desc"><?php echo $data['desc']; ?></div>
          <?php endif; ?>

          <?php if ($data['expired']): ?>
            <div class="wpp__expired">Hlasování skončilo.</div>
            <?php echo $this->render_results($data); ?>
          <?php elseif ($voted): ?>
            <div class="wpp__notice">Již jste hlasoval.</div>
            <?php echo $this->render_results($data); ?>
          <?php else: ?>
            <form class="wpp__form" data-nonce="<?php echo esc_attr(wp_create_nonce('wpp_vote_'.$id)); ?>">
              <div class="wpp__options">
                <?php foreach ($data['options'] as $i=>$label): ?>
                  <label class="wpp__option">
                    <input class="wpp__input" type="<?php echo $input_type; ?>" name="<?php echo esc_attr($choices_name); ?>" value="<?php echo esc_attr($i); ?>">
                    <span class="wpp__label"><?php echo esc_html($label); ?></span>
                  </label>
                <?php endforeach; ?>
              </div>
              <div class="wpp__actions">
                <button type="submit" class="wpp__btn">Hlasovat</button>
              </div>
            </form>
            <?php if ($data['show_before']) echo $this->render_results($data, true); ?>
          <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function render_results($data, $collapsed=false) {
        $total = array_sum($data['votes']);
        if ($total < 1) $total = 1;
        ob_start();
        ?>
        <div class="wpp__results<?php echo $collapsed?' wpp__results--collapsed':''; ?>" aria-live="polite">
          <?php foreach ($data['options'] as $i=>$label):
                $count = intval($data['votes'][$i] ?? 0);
                $pct = round($count*100/$total);
          ?>
            <div class="wpp__bar">
              <div class="wpp__bar-label">
                <span class="wpp__bar-text"><?php echo esc_html($label); ?></span>
                <span class="wpp__bar-val"><?php echo $pct; ?>% (<?php echo $count; ?>)</span>
              </div>
              <div class="wpp__bar-track">
                <div class="wpp__bar-fill" style="width:<?php echo $pct; ?>%"></div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    private function has_voted($poll_id) {
        $cookie = self::COOKIE_PREFIX.$poll_id;
        if (!empty($_COOKIE[$cookie])) return true;
        $ip = $this->client_ip();
        $key = 'wpp_lock_'.md5($poll_id.'|'.$ip.'|'.wp_salt('auth'));
        return (bool) get_transient($key);
    }

    public function ajax_vote() {
        $poll_id = isset($_POST['poll']) ? intval($_POST['poll']) : 0;
        $nonce = isset($_POST['nonce']) ? sanitize_text_field($_POST['nonce']) : '';
        if (!$poll_id || !wp_verify_nonce($nonce, 'wpp_vote_'.$poll_id)) wp_send_json_error(['msg'=>'Neplatná žádost'], 400);

        $data = $this->poll_data($poll_id);
        if (!$data) wp_send_json_error(['msg'=>'Anketa nenalezena'], 404);
        if ($data['expired']) wp_send_json_error(['msg'=>'Hlasování skončilo'], 400);
        if ($this->has_voted($poll_id)) wp_send_json_error(['msg'=>'Už jste hlasoval'], 400);

        $choices = isset($_POST['choices']) ? (array) $_POST['choices'] : [];
        $choices = array_values(array_unique(array_map('intval',$choices)));
        if (empty($choices)) wp_send_json_error(['msg'=>'Vyberte možnost'], 400);
        if (!$data['multi'] && count($choices)>1) $choices = [reset($choices)];

        $max_index = count($data['options']) - 1;
        foreach ($choices as $i) { if ($i<0 || $i>$max_index) wp_send_json_error(['msg'=>'Neplatná volba'], 400); }

        $votes = (array) get_post_meta($poll_id, self::META_VOTES, true);
        if (count($votes)!==count($data['options'])) $votes = array_fill(0, count($data['options']), 0);
        foreach ($choices as $i) { $votes[$i] = intval($votes[$i]) + 1; }
        update_post_meta($poll_id, self::META_VOTES, array_map('intval',$votes));

        // zámek: cookie + transient pro IP
        setcookie(self::COOKIE_PREFIX.$poll_id, '1', time()+365*24*3600, COOKIEPATH ?: '/', COOKIE_DOMAIN, is_ssl(), true);
        $ip = $this->client_ip();
        set_transient('wpp_lock_'.md5($poll_id.'|'.$ip.'|'.wp_salt('auth')), 1, 365*DAY_IN_SECONDS);

        $data = $this->poll_data($poll_id);
        ob_start(); echo $this->render_results($data);
        $html = ob_get_clean();

        wp_send_json_success([
            'total'=>array_sum($data['votes']),
            'results_html'=>$html,
        ]);
    }

    private function client_ip() {
        foreach (['HTTP_CF_CONNECTING_IP','HTTP_X_FORWARDED_FOR','REMOTE_ADDR'] as $k) {
            if (!empty($_SERVER[$k])) { $raw = explode(',', $_SERVER[$k]); return trim($raw[0]); }
        }
        return '0.0.0.0';
    }
}
add_action('plugins_loaded', fn()=>WPWebklient_Polls::instance());
