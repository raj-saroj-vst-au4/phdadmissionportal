// Shared admit card PDF rendering (jsPDF).
// Expose window.AdmitCard.{ensureAssets, render}.
(function(){
  const CARD_X = 10, CARD_Y = 10, CARD_W = 190, CARD_H = 130;
  const PHOTO_BOX_X = 152, PHOTO_BOX_Y = 32, PHOTO_BOX_W = 35, PHOTO_BOX_H = 45;
  const LOGO_URL = '/phdportal/assets/img/SJMSOM_logo.png';
  const SIGNATURE_URL = '/phdportal/assets/img/sjmsom_Stamp.png';
  const PHOTO_URL_BASE = '/phdportal/uploads/photos/';

  let LOGO_BG = null;
  let SIGNATURE_IMG = null;
  let ASSETS_READY = null;
  const PHOTO_CACHE = {};

  function _loadImage(src) {
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = () => resolve(img);
      img.onerror = () => resolve(null);
      img.src = src;
    });
  }

  function _bakeAlpha(img, alpha) {
    const canvas = document.createElement('canvas');
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    const ctx = canvas.getContext('2d');
    ctx.globalAlpha = alpha;
    ctx.drawImage(img, 0, 0);
    return canvas.toDataURL('image/png');
  }

  function _toDataUrl(img) {
    const canvas = document.createElement('canvas');
    canvas.width = img.naturalWidth;
    canvas.height = img.naturalHeight;
    canvas.getContext('2d').drawImage(img, 0, 0);
    return canvas.toDataURL('image/png');
  }

  async function _loadPhoto(filename) {
    if (!filename) return null;
    if (PHOTO_CACHE[filename] !== undefined) return PHOTO_CACHE[filename];
    const img = await _loadImage(PHOTO_URL_BASE + encodeURIComponent(filename));
    const result = img ? { dataUrl: _toDataUrl(img), w: img.naturalWidth, h: img.naturalHeight } : null;
    PHOTO_CACHE[filename] = result;
    return result;
  }

  function ensureAssets() {
    if (ASSETS_READY) return ASSETS_READY;
    ASSETS_READY = Promise.all([_loadImage(LOGO_URL), _loadImage(SIGNATURE_URL)])
      .then(([logo, sig]) => {
        if (logo) LOGO_BG = { dataUrl: _bakeAlpha(logo, 0.15), w: logo.naturalWidth, h: logo.naturalHeight };
        if (sig)  SIGNATURE_IMG = { dataUrl: _toDataUrl(sig), w: sig.naturalWidth, h: sig.naturalHeight };
      });
    return ASSETS_READY;
  }

  async function render(doc, c, pageFirst, ctx) {
    ctx = ctx || {};
    const intakeName   = ctx.intakeName || '';
    const examDatetime = ctx.examDatetime || '';
    const entranceMode = ctx.entranceMode || '';
    if (!pageFirst) doc.addPage();
    const photo = await _loadPhoto(c.photo);
    if (LOGO_BG) {
      const maxW = CARD_W * 0.5, maxH = CARD_H * 0.5;
      const ratio = LOGO_BG.w / LOGO_BG.h;
      let w = maxW, h = maxW / ratio;
      if (h > maxH) { h = maxH; w = maxH * ratio; }
      const x = CARD_X + (CARD_W - w) / 2;
      const y = CARD_Y + (CARD_H - h) / 2;
      doc.addImage(LOGO_BG.dataUrl, 'PNG', x, y, w, h);
    }
    doc.setDrawColor(79,70,229); doc.setLineWidth(0.8);
    doc.rect(CARD_X, CARD_Y, CARD_W, CARD_H);
    doc.setFillColor(79,70,229);
    doc.rect(CARD_X, CARD_Y, CARD_W, 18, 'F');
    doc.setTextColor(255,255,255);
    doc.setFontSize(14); doc.setFont(undefined,'bold');
    doc.text('SJMSOM IIT BOMBAY — PhD ADMISSIONS', 105, 18, {align:'center'});
    doc.setFontSize(10); doc.setFont(undefined,'normal');
    const modeLabel = entranceMode ? entranceMode + ' Examination' : 'Entrance Examination';
    doc.text('ADMIT CARD — ' + modeLabel, 105, 24, {align:'center'});
    doc.setTextColor(0,0,0);
    doc.setFontSize(10);
    let y = 38;
    const put = (label, val) => {
      doc.setFont(undefined,'bold'); doc.text(label, 20, y);
      doc.setFont(undefined,'normal'); doc.text(String(val||''), 80, y);
      y += 8;
    };
    put('Intake:', intakeName);
    put('Candidate Name:', c.name);
    put('RMG No (Dept Reg No):', c.dept_reg_no);
    put('PW No:', c.applicant_id || '—');
    put('Exam Date-Time:', examDatetime || '— to be announced —');
    put('Exam Room:', c.room_name || 'Not Allocated');
    doc.setFontSize(8);
    doc.setFont(undefined,'italic');
    const instr = [
      '• Carry a valid photo ID (Aadhaar/Passport/Driving License) and this admit card.',
      '• Report 60 minutes before exam start time.',
      '• Calculators, phones, smart-watches are NOT permitted inside the exam hall.',
      '• Use RMG No. in all communications with the admissions office.',
    ];
    y += 4;
    instr.forEach(line => { doc.text(line, 20, y); y += 5; });
    if (photo) {
      const pRatio = photo.w / photo.h;
      let pw = PHOTO_BOX_W, ph = PHOTO_BOX_W / pRatio;
      if (ph > PHOTO_BOX_H) { ph = PHOTO_BOX_H; pw = PHOTO_BOX_H * pRatio; }
      const px = PHOTO_BOX_X + (PHOTO_BOX_W - pw) / 2;
      const py = PHOTO_BOX_Y + (PHOTO_BOX_H - ph) / 2;
      doc.addImage(photo.dataUrl, 'PNG', px, py, pw, ph);
      doc.setDrawColor(200,200,200); doc.setLineWidth(0.2);
      doc.rect(PHOTO_BOX_X, PHOTO_BOX_Y, PHOTO_BOX_W, PHOTO_BOX_H);
      doc.setDrawColor(79,70,229); doc.setLineWidth(0.8);
    } else {
      doc.setDrawColor(150,150,150); doc.setLineWidth(0.2);
      if (doc.setLineDashPattern) doc.setLineDashPattern([1,1], 0);
      doc.rect(PHOTO_BOX_X, PHOTO_BOX_Y, PHOTO_BOX_W, PHOTO_BOX_H);
      if (doc.setLineDashPattern) doc.setLineDashPattern([], 0);
      doc.setFontSize(10); doc.setTextColor(150,150,150);
      doc.text('photo', PHOTO_BOX_X + PHOTO_BOX_W/2, PHOTO_BOX_Y + PHOTO_BOX_H/2 + 1, {align:'center'});
      doc.setTextColor(0,0,0); doc.setFontSize(10);
      doc.setDrawColor(79,70,229); doc.setLineWidth(0.8);
    }
    doc.setFont(undefined,'normal');
    const sigBoxX = 135, sigBoxY = 106, sigBoxW = 55, sigBoxH = 22;
    if (SIGNATURE_IMG) {
      const ratio = SIGNATURE_IMG.w / SIGNATURE_IMG.h;
      let w = sigBoxW, h = sigBoxW / ratio;
      if (h > sigBoxH) { h = sigBoxH; w = sigBoxH * ratio; }
      const x = sigBoxX + (sigBoxW - w) / 2;
      const yImg = sigBoxY + (sigBoxH - h);
      doc.addImage(SIGNATURE_IMG.dataUrl, 'PNG', x, yImg, w, h);
    } else {
      doc.setDrawColor(150,150,150); doc.setLineWidth(0.2);
      if (doc.setLineDashPattern) doc.setLineDashPattern([1,1], 0);
      doc.rect(sigBoxX, sigBoxY, sigBoxW, sigBoxH);
      if (doc.setLineDashPattern) doc.setLineDashPattern([], 0);
      doc.setFontSize(12); doc.setTextColor(150,150,150);
      doc.text('signature', sigBoxX + sigBoxW/2, sigBoxY + sigBoxH/2 + 1, {align:'center'});
      doc.setTextColor(0,0,0); doc.setFontSize(10);
      doc.setDrawColor(79,70,229); doc.setLineWidth(0.8);
    }
    doc.text('_______________________', 150, 130);
    doc.text('Admissions Office', 160, 135);
  }

  window.AdmitCard = { ensureAssets, render };
})();
