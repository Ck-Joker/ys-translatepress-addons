/**
 * YS TranslatePress Addons — 列表「快速編輯」預填多語顯示規則
 *
 * WordPress 不會自動預填自訂欄位，這裡於開啟快速編輯時，
 * 依該列 .ys-tp-lang-data 的 data 屬性把模式下拉／語言 checkbox 填回目前值。
 */
( function ( $ ) {
    'use strict';

    if ( typeof inlineEditPost === 'undefined' ) {
        return;
    }

    var wpEdit = inlineEditPost.edit;

    inlineEditPost.edit = function ( id ) {
        wpEdit.apply( this, arguments );

        var postId = ( typeof id === 'object' ) ? parseInt( this.getId( id ), 10 ) : 0;
        if ( ! postId ) {
            return;
        }

        var $row  = $( '#post-' + postId );
        var $edit = $( '#edit-' + postId );
        var $data = $row.find( '.ys-tp-lang-data' );
        if ( ! $data.length || ! $edit.length ) {
            return;
        }

        var mode = String( $data.attr( 'data-mode' ) || 'all' );
        var raw  = String( $data.attr( 'data-list' ) || '' );
        var list = raw ? raw.split( ',' ) : [];

        $edit.find( 'select[name="ys_tp_cr_mode"]' ).val( mode );
        $edit.find( 'input[name="ys_tp_cr_list[]"]' ).each( function () {
            this.checked = ( list.indexOf( this.value ) >= 0 );
        } );
        $edit.find( '.ys-tp-qe-langs' ).toggle( mode !== 'all' );
    };

    // 模式切換 → 顯示／隱藏語言勾選
    $( document ).on( 'change', '.ys-tp-qe select[name="ys_tp_cr_mode"]', function () {
        $( this ).closest( 'fieldset' ).find( '.ys-tp-qe-langs' ).toggle( this.value !== 'all' );
    } );

} )( jQuery );
