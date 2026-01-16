/**
 * Schedule Display - ポップアップ機能
 */
(function() {
    'use strict';
    
    // DOM読み込み完了後に実行
    document.addEventListener('DOMContentLoaded', function() {
        const scheduleItems = document.querySelectorAll('.schedule-item');
        const modal = document.getElementById('schedule-modal');
        const modalOverlay = modal?.querySelector('.schedule-modal-overlay');
        const modalClose = modal?.querySelector('.schedule-modal-close');
        const modalTitle = document.getElementById('schedule-modal-title');
        const modalDate = document.getElementById('schedule-modal-date');
        const modalTime = document.getElementById('schedule-modal-time');
        const modalTimeRow = document.getElementById('schedule-modal-time-row');
        const modalDescription = document.getElementById('schedule-modal-description');
        const modalDescriptionWrapper = document.getElementById('schedule-modal-description-wrapper');
        
        if (!modal || !scheduleItems.length) {
            return;
        }
        
        // ポップアップを開く関数
        function openModal(eventData) {
            // 排他モード：既に開いているポップアップがあれば閉じる
            if (modal.style.display !== 'none') {
                closeModal();
            }
            
            // データを設定
            modalTitle.textContent = eventData.title || '';
            modalDate.textContent = (eventData.date || '') + ' ' + (eventData.weekday || '');
            
            // 時間がある場合のみ表示
            if (eventData.time && eventData.time.trim() !== '') {
                modalTime.textContent = eventData.time;
                modalTimeRow.style.display = 'flex';
            } else {
                modalTimeRow.style.display = 'none';
            }
            
            // 説明がある場合のみ表示
            if (eventData.description && eventData.description.trim() !== '') {
                modalDescription.textContent = eventData.description;
                modalDescriptionWrapper.style.display = 'block';
            } else {
                modalDescriptionWrapper.style.display = 'none';
            }
            
            // モーダルを表示
            modal.style.display = 'flex';
            
            // ボディのスクロールを無効化
            document.body.style.overflow = 'hidden';
            
            // フォーカストラップ（アクセシビリティ）
            modalClose?.focus();
        }
        
        // ポップアップを閉じる関数
        function closeModal() {
            modal.style.display = 'none';
            document.body.style.overflow = '';
        }
        
        // 各スケジュール項目にクリックイベントを追加
        scheduleItems.forEach(function(item) {
            item.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                
                // data属性から情報を取得
                const eventData = {
                    title: this.dataset.eventTitle || '',
                    date: this.dataset.eventDate || '',
                    weekday: this.dataset.eventWeekday || '',
                    time: this.dataset.eventTime || '',
                    description: this.dataset.eventDescription || ''
                };
                
                openModal(eventData);
            });
        });
        
        // 閉じるボタンのクリックイベント
        if (modalClose) {
            modalClose.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeModal();
            });
        }
        
        // オーバーレイのクリックイベント（背景をクリックして閉じる）
        if (modalOverlay) {
            modalOverlay.addEventListener('click', function(e) {
                e.preventDefault();
                e.stopPropagation();
                closeModal();
            });
        }
        
        // ESCキーで閉じる
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && modal.style.display !== 'none') {
                closeModal();
            }
        });
        
        // モーダルコンテンツ内のクリックは閉じないようにする
        const modalContent = modal.querySelector('.schedule-modal-content');
        if (modalContent) {
            modalContent.addEventListener('click', function(e) {
                e.stopPropagation();
            });
        }
    });
})();
