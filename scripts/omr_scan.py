#!/usr/bin/env python3
"""
OMR scanner for SJMSOM PhD Admissions portal.

Pipeline:
  PDF -> page images (pdftoppm)
  per page:
    - decode QR (cv2.QRCodeDetector) -> candidate identifier
    - locate 4 fiducial squares -> perspective-warp the page to canonical mm
    - sample each bubble center -> "filled" if mean intensity below threshold
    - emit { qr, answers: {q: 'A'|'B'|'C'|'D'|'-' or 'X' for multi}, page_image }

Geometry MUST match public/assets/js/omr.js : layoutFor(n).
A4: 210 x 297 mm. We render at ~150 DPI so 1mm ~= 5.91 px.
"""
import argparse
import json
import math
import os
import subprocess
import sys
import tempfile
from pathlib import Path

import cv2
import numpy as np

# ---- Layout constants (MUST mirror omr.js) ----
PAGE_W_MM = 210.0
PAGE_H_MM = 297.0

FID_SIZE_MM = 5.0
FID_TL = (12.0, 99.0)
FID_TR = (193.0, 99.0)
FID_BL = (12.0, 287.0)
FID_BR = (193.0, 287.0)

GRID_X0 = 22.0
GRID_Y0 = 116.0
GRID_X1 = 188.0
GRID_Y1 = 285.0

BUBBLE_R_MM = 1.9
CHOICE_GAP = 6.0
NUM_OFFSET = 7.0


def shape_for(n):
    if n <= 30:
        cols = 2
    elif n <= 60:
        cols = 3
    else:
        cols = 4
    rows = math.ceil(n / cols)
    return cols, rows


def layout_for(n):
    """Return list of (q, choice, x_mm, y_mm) — bubble centers."""
    cols, rows = shape_for(n)
    col_w = (GRID_X1 - GRID_X0) / cols
    row_h = min(7.5, (GRID_Y1 - GRID_Y0) / rows)
    bubbles = []
    for q in range(1, n + 1):
        idx = q - 1
        col = idx // rows
        row = idx % rows
        cell_x = GRID_X0 + col * col_w
        cell_y = GRID_Y0 + row * row_h
        a_x = cell_x + NUM_OFFSET + 4.0
        for i, ch in enumerate(['A', 'B', 'C', 'D']):
            bubbles.append((q, ch, a_x + i * CHOICE_GAP, cell_y))
    return bubbles


# ---- Image processing ----

def render_pdf(pdf_path: str, out_dir: str, dpi: int = 150) -> list:
    """Render PDF pages to PNGs via pdftoppm. Returns sorted list of paths."""
    prefix = os.path.join(out_dir, "page")
    cmd = ["pdftoppm", "-png", "-r", str(dpi), pdf_path, prefix]
    res = subprocess.run(cmd, capture_output=True, text=True)
    if res.returncode != 0:
        raise RuntimeError(f"pdftoppm failed: {res.stderr.strip()}")
    pages = sorted(Path(out_dir).glob("page-*.png"))
    return [str(p) for p in pages]


def find_fiducial_centers(gray: np.ndarray):
    """Locate 4 fiducial filled-black squares at corners. Returns dict or None.

    Strategy: threshold to binary, find dark blobs near each page-corner quadrant,
    pick the largest within size/aspect bounds, and return its centroid.
    """
    h, w = gray.shape
    _, bw = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV + cv2.THRESH_OTSU)

    contours, _ = cv2.findContours(bw, cv2.RETR_EXTERNAL, cv2.CHAIN_APPROX_SIMPLE)
    cands = []
    px_per_mm = (w / PAGE_W_MM + h / PAGE_H_MM) / 2
    target_area = (FID_SIZE_MM * px_per_mm) ** 2
    for c in contours:
        area = cv2.contourArea(c)
        if area < target_area * 0.25 or area > target_area * 4:
            continue
        x, y, cw, ch = cv2.boundingRect(c)
        if cw == 0 or ch == 0:
            continue
        ar = cw / ch
        if ar < 0.6 or ar > 1.6:
            continue
        # solidity: filled square should be near-rectangular
        rect_area = cw * ch
        if rect_area == 0 or area / rect_area < 0.7:
            continue
        cx = x + cw / 2
        cy = y + ch / 2
        cands.append((cx, cy, area))

    if not cands:
        return None

    # Quadrant-based pick
    mid_x, mid_y = w / 2, h / 2
    quads = {'tl': [], 'tr': [], 'bl': [], 'br': []}
    for cx, cy, area in cands:
        key = ('t' if cy < mid_y else 'b') + ('l' if cx < mid_x else 'r')
        quads[key].append((cx, cy, area))

    centers = {}
    for key, lst in quads.items():
        if not lst:
            return None
        # Prefer the one closest to the expected page corner (within mm).
        if key == 'tl':
            ex, ey = (FID_TL[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_TL[1] + FID_SIZE_MM / 2) * px_per_mm
        elif key == 'tr':
            ex, ey = (FID_TR[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_TR[1] + FID_SIZE_MM / 2) * px_per_mm
        elif key == 'bl':
            ex, ey = (FID_BL[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_BL[1] + FID_SIZE_MM / 2) * px_per_mm
        else:
            ex, ey = (FID_BR[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_BR[1] + FID_SIZE_MM / 2) * px_per_mm
        lst.sort(key=lambda t: (t[0] - ex) ** 2 + (t[1] - ey) ** 2)
        cx, cy, _ = lst[0]
        centers[key] = (cx, cy)
    return centers


def warp_to_canonical(gray: np.ndarray, fid_centers: dict, dpi: int = 150) -> np.ndarray:
    """Perspective-warp so fiducial centers land at canonical pixel coords."""
    px_per_mm = dpi / 25.4
    out_w = int(round(PAGE_W_MM * px_per_mm))
    out_h = int(round(PAGE_H_MM * px_per_mm))

    src = np.array([
        fid_centers['tl'], fid_centers['tr'], fid_centers['bl'], fid_centers['br']
    ], dtype=np.float32)
    dst = np.array([
        ((FID_TL[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_TL[1] + FID_SIZE_MM / 2) * px_per_mm),
        ((FID_TR[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_TR[1] + FID_SIZE_MM / 2) * px_per_mm),
        ((FID_BL[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_BL[1] + FID_SIZE_MM / 2) * px_per_mm),
        ((FID_BR[0] + FID_SIZE_MM / 2) * px_per_mm, (FID_BR[1] + FID_SIZE_MM / 2) * px_per_mm),
    ], dtype=np.float32)
    M = cv2.getPerspectiveTransform(src, dst)
    warped = cv2.warpPerspective(gray, M, (out_w, out_h), flags=cv2.INTER_AREA, borderValue=255)
    return warped, px_per_mm


def preprocess(gray: np.ndarray) -> np.ndarray:
    """CLAHE flattens uneven scanner illumination so dark fills stand out
    consistently across the page. Tile size of 8x8 mm at the page level."""
    clahe = cv2.createCLAHE(clipLimit=2.5, tileGridSize=(8, 8))
    return clahe.apply(gray)


def decode_qr(img: np.ndarray) -> str:
    """Try OpenCV QRCodeDetector on the full image, on a top-right crop, on
    rotated copies (for upside-down or sideways scans), and on an Otsu binary
    fallback. Returns '' if every attempt fails."""
    detector = cv2.QRCodeDetector()
    h, w = img.shape[:2]

    candidates = [img]
    # Top-right crop where QR lives in the canonical layout.
    candidates.append(img[0:int(h * 0.32), int(w * 0.62):w])
    candidates.append(img[0:int(h * 0.20), int(w * 0.75):w])
    # Rotations — handle scans that fed the page upside-down or sideways.
    for k in (1, 2, 3):
        candidates.append(np.rot90(img, k=k))
    # Otsu binarized fallback for low-contrast scans.
    if len(img.shape) == 2:
        _, bw = cv2.threshold(img, 0, 255, cv2.THRESH_BINARY + cv2.THRESH_OTSU)
        candidates.append(bw)
        # Also try on a CLAHE-enhanced version.
        candidates.append(preprocess(img))

    for c in candidates:
        if c is None or c.size == 0:
            continue
        try:
            data, _, _ = detector.detectAndDecode(c)
        except Exception:
            data = None
        if data:
            return data.strip()
    return ""


def auto_rotate_to_canonical(img: np.ndarray) -> tuple:
    """Try the four 90° rotations of the raw page and pick the one whose
    *top-right* region contains a decodable QR — the QR's canonical position.

    Has to happen BEFORE fiducial detection: if the page is upside-down, the
    quadrant-based fiducial assignment swaps each fiducial with its diagonal
    opposite, producing a distorted warp that can't be undone afterwards.

    Returns (oriented_image, qr_text). If no rotation decodes a QR in its
    canonical slot, returns the original image and qr=''."""
    detector = cv2.QRCodeDetector()
    rotations = [
        (None, img),
        (cv2.ROTATE_90_CLOCKWISE, cv2.rotate(img, cv2.ROTATE_90_CLOCKWISE)),
        (cv2.ROTATE_180, cv2.rotate(img, cv2.ROTATE_180)),
        (cv2.ROTATE_90_COUNTERCLOCKWISE, cv2.rotate(img, cv2.ROTATE_90_COUNTERCLOCKWISE)),
    ]
    for _, oriented in rotations:
        h, w = oriented.shape[:2]
        crop = oriented[0:int(h * 0.32), int(w * 0.62):w]
        try:
            data, _, _ = detector.detectAndDecode(crop)
        except Exception:
            data = None
        if data:
            return oriented, data.strip()
    return img, ''


def _refine_center(img: np.ndarray, cx: int, cy: int, r_px: int, search_px: int = 2) -> tuple:
    """Within ±search_px of (cx, cy), find the (x, y) whose surrounding circular
    patch has the lowest mean intensity (= darkest). Absorbs sub-mm warp drift
    that would otherwise sample the bubble's edge instead of its centre."""
    best = (cx, cy, 1e9)
    h, w = img.shape
    for dy in range(-search_px, search_px + 1):
        for dx in range(-search_px, search_px + 1):
            x = cx + dx; y = cy + dy
            x0, x1 = max(0, x - r_px), min(w, x + r_px + 1)
            y0, y1 = max(0, y - r_px), min(h, y + r_px + 1)
            if x1 <= x0 or y1 <= y0:
                continue
            patch = img[y0:y1, x0:x1]
            yy, xx = np.ogrid[:patch.shape[0], :patch.shape[1]]
            mask = (xx - (x - x0)) ** 2 + (yy - (y - y0)) ** 2 <= r_px ** 2
            if not mask.any():
                continue
            score = float(patch[mask].mean())
            if score < best[2]:
                best = (x, y, score)
    return best[0], best[1]


def read_bubbles(warped_gray: np.ndarray, px_per_mm: float, num_q: int) -> dict:
    """Return { q: {choice: darkness} } where darkness is 0..255 (higher=darker).
    Uses refined centres + mean (not median) for sensitivity to partial fills."""
    bubbles = layout_for(num_q)
    h, w = warped_gray.shape
    r_px = max(2, int(round(BUBBLE_R_MM * px_per_mm * 0.80)))
    search_px = max(1, int(round(0.6 * px_per_mm)))  # ~0.6 mm wiggle room
    out = {}
    for (q, ch, x_mm, y_mm) in bubbles:
        cx0 = int(round(x_mm * px_per_mm))
        cy0 = int(round(y_mm * px_per_mm))
        cx, cy = _refine_center(warped_gray, cx0, cy0, r_px, search_px=search_px)
        x0, x1 = max(0, cx - r_px), min(w, cx + r_px + 1)
        y0, y1 = max(0, cy - r_px), min(h, cy + r_px + 1)
        if x1 <= x0 or y1 <= y0:
            continue
        patch = warped_gray[y0:y1, x0:x1]
        yy, xx = np.ogrid[:patch.shape[0], :patch.shape[1]]
        mask = (xx - (cx - x0)) ** 2 + (yy - (cy - y0)) ** 2 <= r_px ** 2
        if not mask.any():
            continue
        # Mean is more sensitive than median to partial-fill cases.
        darkness = float(255.0 - patch[mask].astype(np.float32).mean())
        out.setdefault(q, {})[ch] = darkness
    return out


# Per-question normalization constants. Tuned for 150 DPI scans of A4 OMRs;
# values are in "darkness units" on a 0..255 scale.
FILL_LIFT_THRESHOLD = 22.0   # bubble counts as "filled" if its darkness exceeds the
                             # lightest sibling in the same question by this much
LOW_CONFIDENCE_MARGIN = 30.0 # if (top_lift - second_lift) is below this on average,
                             # flag the page for manual review


def grade_answers(scores: dict, num_q: int) -> tuple:
    """Per-question normalization: subtract the lightest sibling's darkness so
    the printed letter inside each bubble and any page-level lighting bias
    cancel out. A bubble is 'filled' iff its lift exceeds FILL_LIFT_THRESHOLD.

    Returns (answers, q_confidence, page_confidence)
      answers: { q: 'A'|'B'|'C'|'D'|'-' (blank) | 'X' (multi-mark) }
      q_confidence: { q: margin between top and second-place lift }
      page_confidence: median margin across answered/blank questions
    """
    answers = {}
    qconf = {}
    margins_for_page = []
    for q in range(1, num_q + 1):
        per = scores.get(q, {})
        if not per:
            answers[q] = '-'
            qconf[q] = 0.0
            continue
        baseline = min(per.values())
        lifts = {ch: v - baseline for ch, v in per.items()}
        ranked = sorted(lifts.items(), key=lambda kv: -kv[1])
        top_lift = ranked[0][1]
        second_lift = ranked[1][1] if len(ranked) > 1 else 0.0
        margin = top_lift - second_lift
        qconf[q] = round(float(margin), 2)
        margins_for_page.append(float(margin))

        filled = [ch for ch, lift in lifts.items() if lift > FILL_LIFT_THRESHOLD]
        if not filled:
            answers[q] = '-'        # truly blank — no bubble lifts above baseline
        elif len(filled) == 1:
            answers[q] = filled[0]
        else:
            answers[q] = 'X'        # 2+ bubbles meaningfully filled — multi-mark

    page_conf = float(np.median(margins_for_page)) if margins_for_page else 0.0
    return answers, qconf, round(page_conf, 2)


def evaluate(answers, key, num_q: int):
    correct = wrong = blank = multi = 0
    for q in range(1, num_q + 1):
        a = answers.get(q, '-')
        k = key.get(str(q), key.get(q))
        if a == '-':
            blank += 1
        elif a == 'X':
            multi += 1
            wrong += 1  # multi counts as wrong
        elif k and a == str(k).upper():
            correct += 1
        else:
            wrong += 1
    marks = correct * 1.0 - wrong * 0.25
    return {
        'correct': correct, 'wrong': wrong, 'blank': blank, 'multi': multi,
        'marks': round(marks, 2),
    }


def process_pdf(pdf_path, num_q, key, save_pages_dir=None, dpi=150):
    """Process all pages in pdf_path. Yields per-page dicts.

    Pipeline:
      1. Render PDF page → grayscale image (pdftoppm)
      2. CLAHE preprocessing — flattens uneven scanner lighting
      3. Find 4 fiducial squares (quadrant-based, with expected-corner tiebreak)
      4. Perspective-warp to canonical mm coordinates
      5. Auto-correct 180° flip by checking which orientation lets QR decode
      6. Decode QR (with rotation + Otsu fallbacks)
      7. Read bubbles (refined centres + mean darkness)
      8. Grade with per-question normalization (subtract lightest sibling)
      9. Compute page confidence; flag low-confidence pages for review
    """
    with tempfile.TemporaryDirectory() as tmp:
        pages = render_pdf(pdf_path, tmp, dpi=dpi)
        for i, page_path in enumerate(pages, 1):
            raw = cv2.imread(page_path, cv2.IMREAD_GRAYSCALE)
            if raw is None:
                yield {'page': i, 'ok': False, 'error': 'cannot read image', 'qr': ''}
                continue

            img = preprocess(raw)

            # Step 1: auto-rotate so the QR sits in its canonical top-right.
            # This must happen BEFORE fiducial detection so the quadrant-based
            # corner assignment lines up with the canonical layout.
            img, qr = auto_rotate_to_canonical(img)

            # Step 2: full QR decode pass (rotation/Otsu fallbacks) if the
            # quick canonical-slot check above didn't find one.
            if not qr:
                qr = decode_qr(img)

            fids = find_fiducial_centers(img)
            if not fids:
                yield {'page': i, 'ok': False, 'error': 'fiducials not found',
                       'qr': qr, 'confidence': 0.0, 'review_needed': True}
                continue

            warped, px_per_mm = warp_to_canonical(img, fids, dpi=dpi)

            scores = read_bubbles(warped, px_per_mm, num_q)
            answers, qconf, page_conf = grade_answers(scores, num_q)
            evald = evaluate(answers, key, num_q) if key else {
                'correct': 0, 'wrong': 0,
                'blank': sum(1 for v in answers.values() if v == '-'),
                'multi': sum(1 for v in answers.values() if v == 'X'),
                'marks': 0,
            }

            review_needed = (not qr) or (page_conf < LOW_CONFIDENCE_MARGIN)

            saved_name = ''
            if save_pages_dir:
                os.makedirs(save_pages_dir, exist_ok=True)
                stem = (qr or f'unknown_p{i}').replace('/', '_').replace(' ', '_')[:60]
                saved_name = f'{stem}.png'
                cv2.imwrite(os.path.join(save_pages_dir, saved_name), warped)

            yield {
                'page': i, 'ok': True, 'qr': qr,
                'answers': answers, **evald,
                'page_image': saved_name,
                'confidence': page_conf,
                'review_needed': bool(review_needed),
            }


def main():
    ap = argparse.ArgumentParser()
    ap.add_argument('--pdf', required=True)
    ap.add_argument('--num-questions', type=int, required=True)
    ap.add_argument('--key-json', help='Path to JSON file: {"1":"A","2":"B",...}', default=None)
    ap.add_argument('--save-pages-dir', default=None)
    ap.add_argument('--mode', choices=['answer-key', 'bulk'], default='bulk')
    ap.add_argument('--dpi', type=int, default=150)
    args = ap.parse_args()

    key = {}
    if args.key_json and os.path.exists(args.key_json):
        with open(args.key_json) as f:
            key = json.load(f)

    results = []
    for r in process_pdf(args.pdf, args.num_questions, key,
                         save_pages_dir=args.save_pages_dir, dpi=args.dpi):
        results.append(r)

    out = {'mode': args.mode, 'num_questions': args.num_questions, 'pages': results}
    json.dump(out, sys.stdout)
    sys.stdout.write('\n')


if __name__ == '__main__':
    main()
