/* ═══════════════════════════════════════════════════════════════════
   KIDDIETRAC v4 — Educator Screen Enhancements
   Photo capture · Batch check-in · Observation logging
   Loads AFTER screen-educator.js and patches it with new features.
   ═══════════════════════════════════════════════════════════════════ */

(function (window) {
  'use strict';

  const { Api, Dom, Shell } = window.KT;
  const { Modal } = Shell;

  // ─── Photo capture modal ────────────────────────────────────────

  function showPhotoCaptureModal(childIds, roomId) {
    const modal = new Modal('Share a photo');

    const form = Dom.el('form');
    form.appendChild(Dom.el('label', { style: 'display: block; font-weight: 600; margin-bottom: 8px;' }, 'Photo'));
    const fileInput = Dom.el('input', { type: 'file', accept: 'image/*', capture: 'environment', style: 'margin-bottom: 16px; display: block; width: 100%;' });
    form.appendChild(fileInput);

    const previewWrap = Dom.el('div', { style: 'margin-bottom: 16px;' });
    form.appendChild(previewWrap);

    fileInput.addEventListener('change', () => {
      Dom.clear(previewWrap);
      if (fileInput.files && fileInput.files[0]) {
        const url = URL.createObjectURL(fileInput.files[0]);
        previewWrap.appendChild(Dom.el('img', { src: url, style: 'max-width: 100%; max-height: 240px; border-radius: 8px;' }));
      }
    });

    form.appendChild(Dom.el('label', { style: 'display: block; font-weight: 600; margin-bottom: 8px;' }, 'Caption (optional)'));
    const captionInput = Dom.el('textarea', { placeholder: 'What were they doing?', style: 'width: 100%; min-height: 80px; padding: 10px; border: 1px solid var(--ink-300); border-radius: 8px; font-family: inherit; resize: vertical;' });
    form.appendChild(captionInput);

    form.appendChild(Dom.el('p', { style: 'font-size: 13px; color: var(--ink-600); margin-top: 12px;' }, `This photo will be shared with the parents of ${childIds.length} child${childIds.length === 1 ? '' : 'ren'}.`));

    const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 13px;' });
    form.appendChild(status);

    const submitBtn = Dom.el('button', {
      type: 'submit',
      style: 'background: var(--brand-blue); color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 16px; width: 100%;',
    }, 'Share photo');
    form.appendChild(submitBtn);

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      if (!fileInput.files || !fileInput.files[0]) {
        status.style.color = '#c0392b';
        status.textContent = 'Please select a photo first.';
        return;
      }

      submitBtn.disabled = true;
      submitBtn.textContent = 'Uploading…';
      status.style.color = 'var(--ink-600)';
      status.textContent = '';

      try {
        const fd = new FormData();
        fd.append('file', fileInput.files[0]);
        fd.append('child_ids', childIds.join(','));
        if (captionInput.value.trim()) fd.append('caption', captionInput.value.trim());

        const token = sessionStorage.getItem('kt_token');
        const resp = await fetch('/api/v1/provider/photos', {
          method: 'POST',
          headers: { 'Authorization': 'Bearer ' + token, 'Accept': 'application/json' },
          body: fd,
        });

        if (!resp.ok) {
          const err = await resp.json().catch(() => ({}));
          throw new Error(err.message || 'Upload failed: ' + resp.status);
        }

        status.style.color = 'var(--brand-green)';
        status.textContent = '✓ Photo shared with families';
        setTimeout(() => modal.close(), 1200);
      } catch (e) {
        status.style.color = '#c0392b';
        status.textContent = '✗ ' + e.message;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Share photo';
      }
    });

    modal.body.appendChild(form);
    modal.show();
  }

  // ─── Batch check-in modal ───────────────────────────────────────

  function showBatchCheckInModal(roomId, roomName, children) {
    const modal = new Modal(`Batch check-in — ${roomName}`);

    const intro = Dom.el('p', { style: 'color: var(--ink-600); margin: 0 0 16px;' },
      `Select the children who just arrived. Check-in time will be ${new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })}.`);
    modal.body.appendChild(intro);

    const checkboxes = [];
    const list = Dom.el('div', { style: 'max-height: 50vh; overflow-y: auto; border: 1px solid var(--ink-200); border-radius: 8px; padding: 8px;' });

    children.filter(c => !c.is_at_centre).forEach(child => {
      const id = 'batch-child-' + child.id;
      const row = Dom.el('label', {
        for: id,
        style: 'display: flex; align-items: center; gap: 12px; padding: 12px; cursor: pointer; border-bottom: 1px solid var(--ink-100);',
      });
      const cb = Dom.el('input', { type: 'checkbox', id, value: child.id, style: 'width: 22px; height: 22px;' });
      checkboxes.push(cb);
      row.appendChild(cb);
      row.appendChild(Dom.el('div', { style: 'background: var(--brand-green); color: white; width: 36px; height: 36px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-weight: 700;' }, child.initials || (child.first_name?.[0] || '?')));
      const text = Dom.el('div', { style: 'flex: 1;' });
      text.appendChild(Dom.el('div', { style: 'font-weight: 600;' }, child.display_name || child.first_name));
      text.appendChild(Dom.el('div', { style: 'font-size: 12px; color: var(--ink-500);' }, child.age_human || ''));
      row.appendChild(text);
      list.appendChild(row);
    });

    if (checkboxes.length === 0) {
      modal.body.appendChild(Dom.el('p', { style: 'color: var(--ink-500); padding: 24px; text-align: center;' }, 'All children in this room are already checked in! 🎉'));
    } else {
      modal.body.appendChild(list);

      const controls = Dom.el('div', { style: 'display: flex; gap: 8px; margin: 12px 0;' });
      const selectAll = Dom.el('button', { type: 'button', style: 'background: var(--ink-100); border: none; padding: 6px 12px; border-radius: 6px; font-size: 13px; cursor: pointer;' }, 'Select all');
      selectAll.addEventListener('click', () => checkboxes.forEach(cb => cb.checked = true));
      controls.appendChild(selectAll);
      const clearAll = Dom.el('button', { type: 'button', style: 'background: var(--ink-100); border: none; padding: 6px 12px; border-radius: 6px; font-size: 13px; cursor: pointer;' }, 'Clear');
      clearAll.addEventListener('click', () => checkboxes.forEach(cb => cb.checked = false));
      controls.appendChild(clearAll);
      modal.body.appendChild(controls);

      const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 14px;' });
      modal.body.appendChild(status);

      const submitBtn = Dom.el('button', {
        type: 'button',
        style: 'background: var(--brand-green); color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 12px; width: 100%; font-size: 15px;',
      }, 'Check in selected');

      submitBtn.addEventListener('click', async () => {
        const selectedIds = checkboxes.filter(cb => cb.checked).map(cb => parseInt(cb.value, 10));
        if (selectedIds.length === 0) {
          status.style.color = '#c0392b';
          status.textContent = 'Select at least one child.';
          return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = 'Checking in…';

        try {
          const result = await Api.post('/provider/check-in-batch', {
            room_id: roomId,
            child_ids: selectedIds,
            mood: 'happy',
          });
          status.style.color = 'var(--brand-green)';
          status.textContent = `✓ Checked in ${result.checked_in_count} child${result.checked_in_count === 1 ? '' : 'ren'}`;
          if (result.skipped_count > 0) {
            status.textContent += ` (${result.skipped_count} already in)`;
          }
          setTimeout(() => {
            modal.close();
            window.location.reload();
          }, 1500);
        } catch (e) {
          status.style.color = '#c0392b';
          status.textContent = '✗ ' + e.message;
          submitBtn.disabled = false;
          submitBtn.textContent = 'Check in selected';
        }
      });

      modal.body.appendChild(submitBtn);
    }

    modal.show();
  }

  // ─── Observation modal ─────────────────────────────────────────

  function showObservationModal(childId, childName) {
    const modal = new Modal(`Observation — ${childName}`);

    const form = Dom.el('form');

    form.appendChild(Dom.el('label', { style: 'display: block; font-weight: 600; margin-bottom: 8px;' }, 'Domain'));
    const domainSelect = Dom.el('select', { style: 'width: 100%; padding: 10px; border: 1px solid var(--ink-300); border-radius: 8px; margin-bottom: 16px;' });
    [
      ['social_emotional', 'Social & Emotional'],
      ['physical', 'Physical / Motor'],
      ['language_literacy', 'Language & Literacy'],
      ['cognitive', 'Cognitive'],
      ['creative_arts', 'Creative Arts'],
      ['self_care', 'Self-Care'],
      ['outdoor', 'Outdoor Play'],
    ].forEach(([val, label]) => {
      domainSelect.appendChild(Dom.el('option', { value: val }, label));
    });
    form.appendChild(domainSelect);

    form.appendChild(Dom.el('label', { style: 'display: block; font-weight: 600; margin-bottom: 8px;' }, 'Title'));
    const titleInput = Dom.el('input', { type: 'text', placeholder: 'Brief title', required: true, style: 'width: 100%; padding: 10px; border: 1px solid var(--ink-300); border-radius: 8px; margin-bottom: 16px;' });
    form.appendChild(titleInput);

    form.appendChild(Dom.el('label', { style: 'display: block; font-weight: 600; margin-bottom: 8px;' }, 'Details'));
    const bodyInput = Dom.el('textarea', { placeholder: 'What did you observe?', required: true, style: 'width: 100%; min-height: 120px; padding: 10px; border: 1px solid var(--ink-300); border-radius: 8px; font-family: inherit; resize: vertical;' });
    form.appendChild(bodyInput);

    const status = Dom.el('div', { style: 'margin-top: 12px; font-size: 13px;' });
    form.appendChild(status);

    const submitBtn = Dom.el('button', { type: 'submit', style: 'background: var(--brand-blue); color: white; border: none; padding: 12px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; margin-top: 16px; width: 100%;' }, 'Save observation');
    form.appendChild(submitBtn);

    form.addEventListener('submit', async (ev) => {
      ev.preventDefault();
      submitBtn.disabled = true;
      submitBtn.textContent = 'Saving…';

      try {
        await Api.post('/provider/observations', {
          child_id: childId,
          domain: domainSelect.value,
          title: titleInput.value.trim(),
          body: bodyInput.value.trim(),
        });
        status.style.color = 'var(--brand-green)';
        status.textContent = '✓ Observation saved';
        setTimeout(() => modal.close(), 1200);
      } catch (e) {
        status.style.color = '#c0392b';
        status.textContent = '✗ ' + e.message;
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save observation';
      }
    });

    modal.body.appendChild(form);
    modal.show();
  }

  // Expose to window so the existing screen-educator.js can call these
  window.KT = window.KT || {};
  window.KT.showPhotoCaptureModal = showPhotoCaptureModal;
  window.KT.showBatchCheckInModal = showBatchCheckInModal;
  window.KT.showObservationModal = showObservationModal;
})(window);
