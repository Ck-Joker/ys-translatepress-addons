/**
 * YS TranslatePress Addons — 列表「快速編輯」預填多語顯示規則
 *
 * WordPress 不會自動預填自訂欄位，這裡於開啟快速編輯時，依該列
 * .ys-tp-lang-data 的 data 屬性把模式下拉／語言 checkbox 填回目前值。
 * 同一支腳本涵蓋文章列表（inlineEditPost）與分類列表（inlineEditTax），
 * 以及分類完整編輯頁的下拉切換。
 */
( function ( $ ) {
    'use strict';

    /**
     * 依列上的 .ys-tp-lang-data 預填編輯列的下拉與語言勾選
     *
     * @param {jQuery} $row  列表列（含 .ys-tp-lang-data）
     * @param {jQuery} $edit 展開的快速編輯列
     */
    function prefill( $row, $edit ) {
        var $data = $row.find( '.ys-tp-lang-data' );
        if ( ! $data.length || ! $edit.length ) {
            return;
        }
        var mode = String( $data.attr( 'data-mode' ) || 'inherit' );
        var raw  = String( $data.attr( 'data-list' ) || '' );
        var list = raw ? raw.split( ',' ) : [];

        $edit.find( 'select[name="ys_tp_cr_mode"]' ).val( mode );
        $edit.find( 'input[name="ys_tp_cr_list[]"]' ).each( function () {
            this.checked = ( list.indexOf( this.value ) >= 0 );
        } );
        $edit.find( '.ys-tp-qe-langs' ).toggle( mode === 'exclude' || mode === 'include' );
    }

    // 文章列表
    if ( typeof inlineEditPost !== 'undefined' ) {
        var wpPostEdit = inlineEditPost.edit;
        inlineEditPost.edit = function ( id ) {
            wpPostEdit.apply( this, arguments );
            var postId = ( typeof id === 'object' ) ? parseInt( this.getId( id ), 10 ) : 0;
            if ( postId ) {
                prefill( $( '#post-' + postId ), $( '#edit-' + postId ) );
            }
        };
    }

    // 分類列表
    if ( typeof inlineEditTax !== 'undefined' ) {
        var wpTaxEdit = inlineEditTax.edit;
        inlineEditTax.edit = function ( id ) {
            wpTaxEdit.apply( this, arguments );
            var termId = ( typeof id === 'object' ) ? parseInt( this.getId( id ), 10 ) : parseInt( id, 10 );
            if ( termId ) {
                prefill( $( '#tag-' + termId ), $( '#edit-' + termId ) );
            }
        };
    }

    // 模式切換 → 顯示／隱藏語言勾選（快速編輯與分類編輯頁共用 .ys-tp-qe 容器）
    $( document ).on( 'change', '.ys-tp-qe select[name="ys_tp_cr_mode"], .ys-tp-qe-wrap select[name="ys_tp_cr_mode"]', function () {
        var $scope = $( this ).closest( '.ys-tp-qe-wrap' );
        if ( ! $scope.length ) {
            $scope = $( this ).closest( '.ys-tp-qe' );
        }
        $scope.find( '.ys-tp-qe-langs' ).toggle( this.value === 'exclude' || this.value === 'include' );
    } );

} )( jQuery );
