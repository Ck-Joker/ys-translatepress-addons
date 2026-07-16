/**
 * YS TranslatePress Addons — 選單項目標題列語言小國旗
 *
 * 在「外觀 → 選單」每個項目的收合標題列顯示各語言旗幟：
 * 該語言會顯示＝彩色＋綠勾、被隱藏＝灰階（與內容列表「語言」欄一致）。
 * 初始狀態由 .ys-tp-menu-langdata（伺服器端計算，含既有欄位沿用）提供；
 * 使用者調整面板勾選後改以勾選狀態即時重繪。
 */
( function ( $ ) {
    'use strict';

    var cfg = window.ysTPMenuFlags || {};
    if ( ! cfg.languages ) {
        return;
    }

    /**
     * 取得項目目前的「顯示語言」集合；null = 所有語言
     *
     * @param {jQuery} $li 選單項目 <li>
     * @return {Array|null}
     */
    function effectiveLangs( $li ) {
        var $all = $li.find( '.ys-tp-menu-all' ).first();
        if ( $all.length ) {
            if ( $all.is( ':checked' ) ) {
                return null;
            }
            var picked = [];
            $li.find( '.ys-tp-menu-langs input:checked' ).each( function () {
                picked.push( this.value );
            } );
            // 沒勾任何語言＝儲存時視為所有語言（與 save_fields 行為一致）
            return picked.length ? picked : null;
        }
        // 面板尚未載入欄位（極端情況）→ 用伺服器端初始資料
        var data = $li.find( '.ys-tp-menu-langdata' ).first().attr( 'data-langs' ) || '';
        return '' === data ? null : data.split( ',' );
    }

    /**
     * 重繪單一項目標題列的旗幟
     *
     * @param {jQuery} $li 選單項目 <li>
     */
    function render( $li ) {
        var $title = $li.find( '.menu-item-handle .item-title' ).first();
        if ( ! $title.length ) {
            return;
        }
        var langs = effectiveLangs( $li );
        var $box  = $title.children( '.ys-tp-menu-flags' );
        if ( ! $box.length ) {
            $box = $( '<span class="ys-tp-menu-flags" />' ).appendTo( $title );
        }
        $box.empty();
        $.each( cfg.languages, function ( code, L ) {
            var visible = ( null === langs ) || ( -1 !== $.inArray( code, langs ) );
            var $f = $( '<span class="ys-tp-mf" />' )
                .toggleClass( 'is-on', visible )
                .toggleClass( 'is-off', ! visible )
                .attr( 'title', visible ? L.name : L.off )
                .append( $( '<img alt="" width="18" height="12" />' ).attr( 'src', L.flag ) );
            if ( visible ) {
                $f.append( '<i class="ys-tp-mf-ck" aria-hidden="true"></i>' );
            }
            $box.append( $f );
        } );
    }

    function renderAll() {
        $( '#menu-to-edit li.menu-item' ).each( function () {
            render( $( this ) );
        } );
    }

    // 面板勾選變更 → 即時重繪該項目
    $( document ).on( 'change', '.ys-tp-menu-all, .ys-tp-menu-langs input', function () {
        render( $( this ).closest( 'li.menu-item' ) );
    } );

    $( renderAll );

    // 新增項目（AJAX 加入清單）→ 補繪；用 MutationObserver 監看清單變動
    var list = document.getElementById( 'menu-to-edit' );
    if ( list && window.MutationObserver ) {
        var timer = null;
        new MutationObserver( function () {
            window.clearTimeout( timer );
            timer = window.setTimeout( renderAll, 200 );
        } ).observe( list, { childList: true } );
    }
} )( jQuery );
