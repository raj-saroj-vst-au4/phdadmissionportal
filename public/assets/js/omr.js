// OMR sheet generation (jsPDF + qrcode-generator).
// Exposes window.OMR.{ensureAssets, layoutFor, render, downloadBlank, downloadAll, downloadAnswerKey}.
//
// IMPORTANT: layoutFor() defines bubble positions in mm. The Python scanner
// (scripts/omr_scan.py) MUST use identical geometry. Any change here requires
// matching changes in layoutFor() inside omr_scan.py.
(function () {
  const PAGE_W = 210, PAGE_H = 297;
  const LOGO_URL = '/phdportal/assets/img/SJMSOM_logo.png';

  // Header band (top ~30%): logo, title, QR, instructions, signatures.
  const HEADER_BOTTOM_Y = 95;

  // Fiducial squares (filled black) - corners of OMR region.
  const FID_SIZE = 5;
  const FID_TL = { x: 12,  y: 99 };
  const FID_TR = { x: 193, y: 99 };
  const FID_BL = { x: 12,  y: 287 };
  const FID_BR = { x: 193, y: 287 };

  // Bubble grid bounds (inside fiducials).
  const GRID_X0 = 22;
  const GRID_Y0 = 116;
  const GRID_X1 = 188;
  const GRID_Y1 = 285;

  const BUBBLE_R = 1.9;          // radius mm
  const CHOICE_GAP = 6;          // distance between bubble centers (A→B etc)
  const NUM_OFFSET = 7;          // distance from leftmost bubble to question num text

  let LOGO_IMG = null;
  let ASSETS_READY = null;

  function _loadImage(src) {
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => resolve(null);
      img.src = src;
    });
  }

  function _toDataUrl(img) {
    const canvas = document.createElement('canvas');
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    canvas.getContext('2d').drawImage(img, 0, 0);
    return canvas.toDataURL('image/png');
  }

  function ensureAssets() {
    if (ASSETS_READY) return ASSETS_READY;
    ASSETS_READY = _loadImage(LOGO_URL).then(img => {
      if (img) LOGO_IMG = { dataUrl: _toDataUrl(img), w: img.naturalWidth, h: img.naturalHeight };
    });
    return ASSETS_READY;
  }

  // Compute (cols, rows_per_col) for N questions.
  function _shape(n) {
    if (n <= 30) return { cols: 2, rows: Math.ceil(n / 2) };
    if (n <= 60) return { cols: 3, rows: Math.ceil(n / 3) };
    return { cols: 4, rows: Math.ceil(n / 4) };
  }

  // Returns { num_questions, cols, rows, bubbles: [{q, choice, x, y}], qnums: [{q, x, y}] }
  // Coordinates are bubble centers in mm.
  function layoutFor(n) {
    const { cols, rows } = _shape(n);
    const colW = (GRID_X1 - GRID_X0) / cols;
    const rowH = Math.min(7.5, (GRID_Y1 - GRID_Y0) / rows);
    const bubbles = [];
    const qnums = [];
    for (let q = 1; q <= n; q++) {
      const idx = q - 1;
      const col = Math.floor(idx / rows);
      const row = idx % rows;
      const cellX = GRID_X0 + col * colW;
      const cellY = GRID_Y0 + row * rowH;
      const aX = cellX + NUM_OFFSET + 4;
      // Anchor the question number at the right edge so its baseline lines up
      // with the bubble centres horizontally (right-aligned just before bubble A).
      qnums.push({ q, x: aX - BUBBLE_R - 1.6, y: cellY });
      ['A', 'B', 'C', 'D'].forEach((ch, i) => {
        bubbles.push({ q, choice: ch, x: aX + i * CHOICE_GAP, y: cellY });
      });
    }
    return { num_questions: n, cols, rows, row_h: rowH, col_w: colW, bubbles, qnums };
  }

  function _qrDataUrl(text, sizePx) {
    if (!text) text = ' ';
    const qr = qrcode(0, 'M');
    qr.addData(String(text));
    qr.make();
    const cells = qr.getModuleCount();
    const margin = 4;
    const totalCells = cells + margin * 2;
    const cellSize = Math.max(2, Math.floor(sizePx / totalCells));
    const px = cellSize * totalCells;
    const canvas = document.createElement('canvas');
    canvas.width = px; canvas.height = px;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff';
    ctx.fillRect(0, 0, px, px);
    ctx.fillStyle = '#000';
    for (let r = 0; r < cells; r++) {
      for (let c = 0; c < cells; c++) {
        if (qr.isDark(r, c)) {
          ctx.fillRect((c + margin) * cellSize, (r + margin) * cellSize, cellSize, cellSize);
        }
      }
    }
    return canvas.toDataURL('image/png');
  }

  // Draw header (top 30%).
  function _drawHeader(doc, opts) {
    const intakeName = opts.intakeName || '';
    const candidate = opts.candidate || null;   // {name, dept_reg_no, qr_text}
    const isAnswerKey = !!opts.answerKey;
    const numQ = opts.numQuestions;

    // Logo: preserve native aspect (513x197 ≈ 2.6:1) so it isn't squashed.
    const logoW = 40, logoH = 15;
    if (LOGO_IMG) {
      doc.addImage(LOGO_IMG.dataUrl, 'PNG', 12, 10, logoW, logoH);
    }
    const titleX = 12 + logoW + 4;
    doc.setFont(undefined, 'bold');
    doc.setFontSize(15);
    doc.text('SJMSOM, IIT BOMBAY', titleX, 18);
    doc.setFontSize(11);
    doc.setFont(undefined, 'normal');
    doc.text('PhD Admissions — Entrance Exam OMR Answer Sheet', titleX, 24);
    if (intakeName) {
      doc.setFontSize(9);
      doc.text('Intake: ' + intakeName + '   |   Total Questions: ' + numQ, titleX, 30);
    }

    // QR code box at top-right.
    // Blank-template downloads (no candidate, not an answer-key sheet) get a
    // visual placeholder instead of an encoded QR — the real QR is added later
    // when per-candidate sheets are generated.
    const isBlankTemplate = !candidate && !isAnswerKey;
    const qrSize = 30;
    const qrX = 168, qrY = 10;
    if (isBlankTemplate) {
      doc.setLineWidth(0.3);
      doc.setDrawColor(120, 120, 120);
      if (doc.setLineDashPattern) doc.setLineDashPattern([1, 1], 0);
      doc.rect(qrX, qrY, qrSize, qrSize);
      if (doc.setLineDashPattern) doc.setLineDashPattern([], 0);
      doc.setFontSize(8);
      doc.setTextColor(140, 140, 140);
      doc.text('QR PLACEHOLDER', qrX + qrSize / 2, qrY + qrSize / 2, { align: 'center' });
      doc.setFontSize(6);
      doc.text('(printed per-candidate)', qrX + qrSize / 2, qrY + qrSize / 2 + 4, { align: 'center' });
      doc.setTextColor(0, 0, 0);
      doc.setDrawColor(0, 0, 0);
    } else {
      const qrText = isAnswerKey ? ('KEY:' + numQ)
                                 : (candidate.qr_text || candidate.dept_reg_no || '');
      doc.addImage(_qrDataUrl(qrText, 256), 'PNG', qrX, qrY, qrSize, qrSize);
    }
    doc.setFontSize(7);
    doc.setTextColor(80, 80, 80);
    doc.text(isAnswerKey ? 'ANSWER KEY' : 'Candidate ID', qrX + qrSize / 2, qrY + qrSize + 3, { align: 'center' });
    doc.setTextColor(0, 0, 0);

    // Candidate name / RMG line.
    doc.setLineWidth(0.2);
    doc.setDrawColor(0, 0, 0);
    doc.rect(12, 38, 154, 8);
    doc.setFontSize(8);
    doc.text('Name of the Candidate:', 14, 43);
    if (candidate && candidate.name) {
      doc.setFont(undefined, 'bold'); doc.setFontSize(10);
      doc.text(String(candidate.name), 56, 44);
      doc.setFont(undefined, 'normal');
    }
    doc.rect(12, 47, 154, 8);
    doc.setFontSize(8);
    doc.text('RMG / Dept Reg No:', 14, 52);
    if (candidate && candidate.dept_reg_no) {
      doc.setFont(undefined, 'bold'); doc.setFontSize(10);
      doc.text(String(candidate.dept_reg_no), 56, 53);
      doc.setFont(undefined, 'normal');
    }

    // Instructions
    doc.setFontSize(8);
    doc.setFont(undefined, 'bold');
    doc.text('Important Instructions:', 12, 60);
    doc.setFont(undefined, 'normal');
    const ins = [
      '1. Use BLACK or BLUE BALL POINT PEN only. Do NOT use pencil or gel pen.',
      '2. Darken ONLY ONE circle for each question. Multiple/partial marks are treated as wrong.',
      '3. Do not fold, tear or stain the sheet. Keep the QR code area clean and unmarked.',
      '4. Marking scheme: +1 for each correct answer; -0.25 for each wrong answer; 0 for blank.',
    ];
    let yy = 64;
    ins.forEach(line => { doc.text(line, 12, yy); yy += 4; });

    // Signature boxes.
    doc.setFontSize(8);
    doc.rect(12, 78, 90, 14);
    doc.text('Candidate Signature', 14, 82);
    doc.rect(106, 78, 90, 14);
    doc.text('Invigilator Signature', 108, 82);

    // PART B label
    doc.setFontSize(10);
    doc.setFont(undefined, 'bold');
    doc.text('PART - B  (Answers)', 12, HEADER_BOTTOM_Y + 1);
    doc.setFont(undefined, 'normal');
  }

  function _drawFiducials(doc) {
    doc.setFillColor(0, 0, 0);
    [FID_TL, FID_TR, FID_BL, FID_BR].forEach(p => {
      doc.rect(p.x, p.y, FID_SIZE, FID_SIZE, 'F');
    });
  }

  function _drawGrid(doc, layout, fillAnswers) {
    // fillAnswers: optional map { qNum: 'A'|'B'|'C'|'D' } — used for answer-key sheet.
    doc.setLineWidth(0.25);
    doc.setDrawColor(60, 60, 60);

    // Question numbers — right-aligned just before the A bubble, vertically
    // centred on the bubble row so they sit on the same line as the choices.
    doc.setFont(undefined, 'bold');
    doc.setFontSize(8.5);
    layout.qnums.forEach(qn => {
      doc.text(String(qn.q), qn.x, qn.y, { align: 'right', baseline: 'middle' });
    });
    doc.setFont(undefined, 'normal');

    layout.bubbles.forEach(b => {
      doc.circle(b.x, b.y, BUBBLE_R, 'S');
      doc.setFontSize(5);
      doc.text(b.choice, b.x, b.y, { align: 'center', baseline: 'middle' });
      if (fillAnswers && fillAnswers[b.q] && fillAnswers[b.q].toUpperCase() === b.choice) {
        doc.setFillColor(0, 0, 0);
        doc.circle(b.x, b.y, BUBBLE_R - 0.25, 'F');
      }
    });
  }

  // Render one OMR page on the given doc (caller adds new pages as needed).
  async function render(doc, opts) {
    await ensureAssets();
    const layout = layoutFor(opts.numQuestions);
    _drawHeader(doc, opts);
    _drawFiducials(doc);
    _drawGrid(doc, layout, opts.fillAnswers || null);
  }

  async function downloadBlank(numQ, intakeName) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    await render(doc, { numQuestions: numQ, intakeName });
    doc.save('OMR_Blank_' + numQ + 'Q.pdf');
  }

  async function downloadAll(numQ, intakeName, candidates, onProgress) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    for (let i = 0; i < candidates.length; i++) {
      if (i > 0) doc.addPage();
      const c = candidates[i];
      await render(doc, {
        numQuestions: numQ,
        intakeName,
        candidate: { name: c.name, dept_reg_no: c.dept_reg_no, qr_text: c.dept_reg_no },
      });
      if (onProgress) onProgress(i + 1, candidates.length);
      // Yield so the UI can repaint the progress overlay between pages.
      if ((i & 3) === 3) await new Promise(r => setTimeout(r, 0));
    }
    doc.save('OMR_Sheets_' + (intakeName || 'all').replace(/\s+/g, '_') + '.pdf');
  }

  async function downloadAnswerKey(numQ, intakeName, answers) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });
    await render(doc, { numQuestions: numQ, intakeName, answerKey: true, fillAnswers: answers });
    doc.save('OMR_AnswerKey_' + numQ + 'Q.pdf');
  }

  window.OMR = { ensureAssets, layoutFor, render, downloadBlank, downloadAll, downloadAnswerKey };
})();
