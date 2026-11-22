<?php
namespace YangSheep\TPAddons\Modules\GoogleGlossary;

use YangSheep\TPAddons\Modules\YSTPAddonsModuleInterface;
use YangSheep\TPAddons\Support\YSTPAddonsTP;

defined( 'ABSPATH' ) || exit;

class YSTPAddonsGoogleGlossary implements YSTPAddonsModuleInterface {
    public function boot(): void {
        if ( ! class_exists( '\TRP_Glossary' ) || ! class_exists( '\TRP_Machine_Translation_Spans' ) ) {
            return;
        }
        $machine = (array) get_option( 'trp_machine_translation_settings', [] );
        if ( ( $machine['translation-engine'] ?? '' ) !== 'google_translate_v2' ) {
            return;
        }

        // 維持核心術語格式及保護標記，並由核心接回對應譯文。
        $glossary = new class( YSTPAddonsTP::settings() ) extends \TRP_Glossary {
            public function __construct( array $settings ) {
                parent::__construct( $settings );
                remove_filter( 'trp_settings_active_tab', [ $this, 'filter_active_tab' ] );
            }

            public function is_tp_ai_active(): bool {
                return true;
            }
        };

        add_filter( 'trp_machine_translation_span_terms', [ $glossary, 'add_span_terms' ], 10, 4 );
        $core = \TRP_Translate_Press::get_trp_instance()->get_component( 'glossary' );
        if ( $core && remove_action( 'admin_menu', [ $core, 'add_submenu_page' ] ) ) {
            add_action( 'admin_menu', [ $glossary, 'add_submenu_page' ] );
        }
    }
}
