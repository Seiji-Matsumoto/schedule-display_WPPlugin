/**
 * Schedule Display - ポップアップ機能
 */
(function() {
    'use strict';
    
    // DOM読み込み完了後に実行
    document.addEventListener('DOMContentLoaded', function() {
        const modal = document.getElementById('schedule-modal');
        const modalOverlay = modal?.querySelector('.schedule-modal-overlay');
        const modalClose = modal?.querySelector('.schedule-modal-close');
        const modalTitle = document.getElementById('schedule-modal-title');
        const modalDate = document.getElementById('schedule-modal-date');
        const modalTime = document.getElementById('schedule-modal-time');
        const modalTimeRow = document.getElementById('schedule-modal-time-row');
        const modalLocation = document.getElementById('schedule-modal-location');
        const modalLocationRow = document.getElementById('schedule-modal-location-row');
        const modalDescription = document.getElementById('schedule-modal-description');
        const modalDescriptionWrapper = document.getElementById('schedule-modal-description-wrapper');
        
        // 設定値を取得（WordPressから渡された値）
        const modalSettings = {
            showLocation: (typeof scheduleModalSettings !== 'undefined' && scheduleModalSettings.showLocation) ? parseInt(scheduleModalSettings.showLocation, 10) : 0,
            showDescription: (typeof scheduleModalSettings !== 'undefined' && scheduleModalSettings.showDescription) ? parseInt(scheduleModalSettings.showDescription, 10) : 1
        };
        
        if (!modal) {
            return;
        }
        
        // ポップアップを開く関数（排他モード）
        function openModal(eventData) {
            // 排他モード：既に開いているポップアップがあれば閉じる
            if (modal && modal.style.display !== 'none' && modal.style.display !== '') {
                closeModal();
                // 少し待ってから新しいモーダルを開く（アニメーションのため）
                setTimeout(function() {
                    showModal(eventData);
                }, 100);
            } else {
                showModal(eventData);
            }
        }
        
        // モーダルを表示する関数
        function showModal(eventData) {
            if (!modal || !modalTitle || !modalDate) {
                return;
            }
            
            // データを設定
            // タイトル：必須（常に表示）
            modalTitle.textContent = eventData.title || '';
            
            // 日付：必須（常に表示）
            modalDate.textContent = (eventData.date || '') + ' ' + (eventData.weekday || '');
            
            // 時間：必須（常に表示、時間がある場合のみ表示）
            if (eventData.time && eventData.time.trim() !== '') {
                if (modalTime) modalTime.textContent = eventData.time;
                if (modalTimeRow) modalTimeRow.style.display = 'flex';
            } else {
                if (modalTimeRow) modalTimeRow.style.display = 'none';
            }
            
            // 場所：設定に基づいて表示/非表示
            if (modalSettings.showLocation && eventData.location && eventData.location.trim() !== '') {
                if (modalLocation) modalLocation.textContent = eventData.location;
                if (modalLocationRow) modalLocationRow.style.display = 'flex';
            } else {
                if (modalLocationRow) modalLocationRow.style.display = 'none';
            }
            
            // 説明：設定に基づいて表示/非表示
            if (modalSettings.showDescription && eventData.description && eventData.description.trim() !== '') {
                if (modalDescription) modalDescription.textContent = eventData.description;
                if (modalDescriptionWrapper) modalDescriptionWrapper.style.display = 'block';
            } else {
                if (modalDescriptionWrapper) modalDescriptionWrapper.style.display = 'none';
            }
            
            // モーダルを表示（排他モード：他の要素の上に表示）
            modal.style.display = 'flex';
            modal.style.zIndex = '99999'; // 高いz-indexで確実に最前面に表示
            
            // ボディのスクロールを無効化
            document.body.style.overflow = 'hidden';
            
            // フォーカストラップ（アクセシビリティ）
            if (modalClose) {
                modalClose.focus();
            }
        }
        
        // ポップアップを閉じる関数
        function closeModal() {
            if (modal) {
                modal.style.display = 'none';
                modal.style.zIndex = '';
            }
            document.body.style.overflow = '';
        }
        
        // 吹き出しリストを表示する関数
        function showEventPopup(events, dateKey, triggerElement) {
            const popup = document.getElementById('schedule-event-popup');
            const popupDate = document.getElementById('schedule-event-popup-date');
            const popupList = document.getElementById('schedule-event-popup-list');
            const popupClose = popup?.querySelector('.schedule-event-popup-close');
            
            if (!popup || !popupList) {
                return;
            }
            
            // 日付を設定
            if (popupDate && events.length > 0) {
                popupDate.textContent = events[0].date + ' ' + events[0].weekday;
            }
            
            // イベントリストをクリア
            popupList.innerHTML = '';
            
            // 各イベントをリストに追加
            events.forEach(function(event, index) {
                const listItem = document.createElement('div');
                listItem.className = 'schedule-event-popup-item';
                listItem.style.cursor = 'pointer';
                listItem.innerHTML = '<div class="schedule-event-popup-item-title">' + 
                    (event.time ? '<span class="schedule-event-popup-item-time">' + event.time + '</span> ' : '') +
                    '<span class="schedule-event-popup-item-title-text">' + (event.title || '（タイトルなし）') + '</span>' +
                    '</div>';
                
                // クリックで詳細モーダルを表示
                listItem.addEventListener('click', function() {
                    closeEventPopup();
                    openModal(event);
                });
                
                popupList.appendChild(listItem);
            });
            
            // 吹き出しの位置を計算（トリガー要素の位置に合わせる）
            const rect = triggerElement.getBoundingClientRect();
            popup.style.position = 'fixed';
            popup.style.top = (rect.bottom + 5) + 'px';
            popup.style.left = rect.left + 'px';
            popup.style.display = 'block';
            popup.style.zIndex = '99998';
            
            // 閉じるボタンのイベント
            if (popupClose) {
                popupClose.onclick = function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    closeEventPopup();
                };
            }
            
            // 背景クリックで閉じる
            document.addEventListener('click', function closeOnOutsideClick(e) {
                if (!popup.contains(e.target) && !triggerElement.contains(e.target)) {
                    closeEventPopup();
                    document.removeEventListener('click', closeOnOutsideClick);
                }
            });
        }
        
        // 吹き出しリストを閉じる関数
        function closeEventPopup() {
            const popup = document.getElementById('schedule-event-popup');
            if (popup) {
                popup.style.display = 'none';
            }
        }
        
        // イベント委譲を使用してクリックイベントを設定（動的に生成される要素にも対応）
        const scheduleContainer = document.querySelector('.schedule-container');
        
        if (scheduleContainer) {
            // リスト表示のスケジュール項目のクリック
            scheduleContainer.addEventListener('click', function(e) {
                const scheduleItem = e.target.closest('.schedule-item');
                if (scheduleItem) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // data属性から情報を取得
                    const eventData = {
                        title: scheduleItem.dataset.eventTitle || '',
                        date: scheduleItem.dataset.eventDate || '',
                        weekday: scheduleItem.dataset.eventWeekday || '',
                        time: scheduleItem.dataset.eventTime || '',
                        location: scheduleItem.dataset.eventLocation || '',
                        description: scheduleItem.dataset.eventDescription || ''
                    };
                    
                    openModal(eventData);
                    return;
                }
                
                // カレンダー表示のイベントのクリック
                const calendarEvent = e.target.closest('.schedule-calendar-event');
                if (calendarEvent) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // data属性から情報を取得
                    const eventData = {
                        title: calendarEvent.dataset.eventTitle || '',
                        date: calendarEvent.dataset.eventDate || '',
                        weekday: calendarEvent.dataset.eventWeekday || '',
                        time: calendarEvent.dataset.eventTime || '',
                        location: calendarEvent.dataset.eventLocation || '',
                        description: calendarEvent.dataset.eventDescription || ''
                    };
                    
                    openModal(eventData);
                    return;
                }
                
                // 「+N件」をクリックした場合、吹き出しリストを表示
                const eventMore = e.target.closest('.schedule-calendar-event-more');
                if (eventMore) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    const eventsJson = eventMore.getAttribute('data-events');
                    const dateKey = eventMore.getAttribute('data-date-key');
                    
                    if (eventsJson) {
                        try {
                            const events = JSON.parse(eventsJson);
                            showEventPopup(events, dateKey, eventMore);
                        } catch (err) {
                            console.error('Failed to parse events:', err);
                        }
                    }
                    return;
                }
                
                // カレンダーの日付セルをクリックした場合、その日の最初のイベントを表示
                const calendarDay = e.target.closest('.schedule-calendar-day-has-events');
                if (calendarDay && !e.target.closest('.schedule-calendar-event') && !e.target.closest('.schedule-calendar-event-more')) {
                    e.preventDefault();
                    e.stopPropagation();
                    
                    // その日の最初のイベントを取得
                    const firstEvent = calendarDay.querySelector('.schedule-calendar-event');
                    if (firstEvent) {
                        const eventData = {
                            title: firstEvent.dataset.eventTitle || '',
                            date: firstEvent.dataset.eventDate || '',
                            weekday: firstEvent.dataset.eventWeekday || '',
                            time: firstEvent.dataset.eventTime || '',
                            location: firstEvent.dataset.eventLocation || '',
                            description: firstEvent.dataset.eventDescription || ''
                        };
                        openModal(eventData);
                    }
                }
            });
        }
        
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
