/**
 * SIRE — screenshot capture using existing browser capabilities only.
 *
 * No new dependency. html2canvas / modern-screenshot were not added: the CRM has
 * no such package today, and getDisplayMedia is already available in every
 * browser this app supports.
 *
 * Trade-off, stated plainly: getDisplayMedia shows the browser's own picker, so
 * the user chooses what is shared and may pick the wrong surface. That is also
 * its safety property — nothing is captured without an explicit user gesture and
 * an explicit choice. When it is unavailable or declined, the flow degrades to
 * "attach a screenshot file", which always works.
 *
 * NO image editor is built. The CRM has no canvas-annotation infrastructure
 * (no fabric.js, no konva), and the brief says not to build one.
 */

const MAX_EDGE = 1600;      // downscale ceiling — evidence, not print quality
const QUALITY = 0.75;       // disk on the production box is tight

export const canCaptureScreen = () =>
  typeof navigator !== 'undefined' &&
  typeof navigator.mediaDevices?.getDisplayMedia === 'function' &&
  typeof window !== 'undefined' &&
  window.isSecureContext !== false;

function drawScaled(source, width, height) {
  const scale = Math.min(1, MAX_EDGE / Math.max(width, height));
  const canvas = document.createElement('canvas');
  canvas.width = Math.round(width * scale);
  canvas.height = Math.round(height * scale);
  canvas.getContext('2d').drawImage(source, 0, 0, canvas.width, canvas.height);
  return canvas;
}

/**
 * WebP first: a screenshot re-encodes roughly 25-35% smaller than the same JPEG
 * at this quality, and these accumulate one per reported issue on a box whose
 * disk is tight.
 *
 * canvas.toBlob silently falls back to PNG when it cannot encode the type asked
 * for -- and PNG would be far LARGER than the JPEG we replaced, so the result
 * type is checked rather than trusted, and anything that is not WebP falls back
 * to JPEG explicitly.
 */
const encode = (canvas, type) =>
  new Promise((resolve) => canvas.toBlob(resolve, type, QUALITY));

const toImage = async (canvas) => {
  const webp = await encode(canvas, 'image/webp');
  if (webp && webp.type === 'image/webp') {
    return new File([webp], 'screen.webp', { type: 'image/webp' });
  }

  const jpeg = await encode(canvas, 'image/jpeg');
  return jpeg ? new File([jpeg], 'screen.jpg', { type: 'image/jpeg' }) : null;
};

/** Smallest drag worth treating as a deliberate selection, in CSS pixels. */
const MIN_SELECTION = 12;

/**
 * Let the user drag a rectangle over the frame that was just captured.
 *
 * Resolves to {x, y, width, height} in SOURCE pixels, or null to keep the whole
 * frame. Cancelling is not a failure: "actually, the whole screen" is a normal
 * answer and the caller treats null that way.
 *
 * Built with DOM calls rather than as a React component on purpose. This runs
 * while the report modal is CLOSED -- the modal has to be out of the way or the
 * screenshot is a picture of the report form -- so at the moment this needs to
 * render there is no SIRE tree mounted to render into. An imperative overlay
 * needs nothing to already exist.
 *
 * Everything is removed in a finally block, including on a thrown error. An
 * overlay that survives its own failure would cover the whole application with
 * an invisible click trap.
 */
function selectRegion(frame) {
  return new Promise((resolve) => {
    const host = document.createElement('div');
    host.setAttribute('data-sire-region', '');
    // Above everything. The Report Issue button sits at 10000 and its modal at
    // 10001 precisely because this application stacks overlays as high as 9999.
    host.style.cssText = [
      'position:fixed', 'inset:0', 'z-index:2147483647',
      'cursor:crosshair', 'user-select:none', 'touch-action:none',
    ].join(';');

    // The captured frame, shown at viewport size. The user drags over a picture
    // of what they just shared rather than over the live page, so nothing
    // underneath can scroll or repaint mid-selection and shift the target.
    const preview = document.createElement('canvas');
    preview.style.cssText = 'position:absolute;inset:0;width:100%;height:100%;display:block';
    preview.width = frame.width;
    preview.height = frame.height;
    preview.getContext('2d').drawImage(frame, 0, 0);
    host.appendChild(preview);

    const shade = document.createElement('div');
    shade.style.cssText = 'position:absolute;inset:0;background:rgba(15,23,42,.55)';
    host.appendChild(shade);

    const box = document.createElement('div');
    box.style.cssText = [
      'position:absolute', 'display:none', 'border:2px solid #38bdf8',
      'background:rgba(56,189,248,.12)', 'box-shadow:0 0 0 9999px rgba(15,23,42,.55)',
      'pointer-events:none',
    ].join(';');
    host.appendChild(box);

    const hint = document.createElement('div');
    hint.textContent = 'Drag to select the part that is wrong — Esc for the whole screen';
    hint.style.cssText = [
      'position:absolute', 'left:50%', 'top:24px', 'transform:translateX(-50%)',
      'padding:8px 14px', 'border-radius:9999px', 'background:rgba(15,23,42,.92)',
      'color:#f8fafc', 'font:500 13px/1.4 system-ui,sans-serif', 'pointer-events:none',
      'white-space:nowrap', 'max-width:calc(100vw - 32px)', 'overflow:hidden',
      'text-overflow:ellipsis',
    ].join(';');
    host.appendChild(hint);

    let startX = 0, startY = 0, dragging = false;

    const rect = (event) => {
      const x = Math.min(startX, event.clientX);
      const y = Math.min(startY, event.clientY);
      return { x, y, width: Math.abs(event.clientX - startX), height: Math.abs(event.clientY - startY) };
    };

    const done = (value) => {
      cleanup();
      resolve(value);
    };

    const onDown = (event) => {
      dragging = true;
      startX = event.clientX;
      startY = event.clientY;
      box.style.display = 'block';
      shade.style.display = 'none';   // the box's own ring shades everything now
      host.setPointerCapture?.(event.pointerId);
    };

    const onMove = (event) => {
      if (!dragging) return;
      const r = rect(event);
      box.style.left = r.x + 'px';
      box.style.top = r.y + 'px';
      box.style.width = r.width + 'px';
      box.style.height = r.height + 'px';
    };

    const onUp = (event) => {
      if (!dragging) return;
      dragging = false;
      const r = rect(event);

      // A click rather than a drag means they changed their mind, not that they
      // want a 2-pixel screenshot.
      if (r.width < MIN_SELECTION || r.height < MIN_SELECTION) {
        done(null);
        return;
      }

      // Viewport CSS pixels -> source pixels. The frame is almost never the same
      // size as the viewport: a HiDPI display, browser zoom and the user picking
      // a different surface in the picker all change the ratio independently.
      const scaleX = frame.width / host.clientWidth;
      const scaleY = frame.height / host.clientHeight;

      done({
        x: Math.max(0, Math.round(r.x * scaleX)),
        y: Math.max(0, Math.round(r.y * scaleY)),
        width: Math.min(frame.width, Math.round(r.width * scaleX)),
        height: Math.min(frame.height, Math.round(r.height * scaleY)),
      });
    };

    const onKey = (event) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        done(null);
      }
    };

    function cleanup() {
      window.removeEventListener('keydown', onKey, true);
      host.remove();
    }

    host.addEventListener('pointerdown', onDown);
    host.addEventListener('pointermove', onMove);
    host.addEventListener('pointerup', onUp);
    host.addEventListener('pointercancel', () => done(null));
    window.addEventListener('keydown', onKey, true);

    document.body.appendChild(host);
  });
}

/** Crop a source frame to a rectangle in source pixels, then downscale. */
function cropTo(frame, area) {
  const canvas = document.createElement('canvas');
  canvas.width = area.width;
  canvas.height = area.height;
  canvas.getContext('2d').drawImage(
    frame,
    area.x, area.y, area.width, area.height,
    0, 0, area.width, area.height,
  );
  return drawScaled(canvas, canvas.width, canvas.height);
}

/**
 * Capture the current screen. Resolves to a File, or null if unsupported or
 * declined — a decline is a normal outcome, not an error to surface.
 *
 * The caller must hide the report modal first (see ReportIssueModal), otherwise
 * the screenshot is a picture of the report form.
 *
 * `segment: true` asks the user to drag a rectangle over the captured frame and
 * keeps only that. A full-screen grab of a dense CRM page is mostly chrome the
 * developer does not need, and it carries whatever else was on screen -- another
 * customer's row in a list behind the dialog, a name in the sidebar. Cropping to
 * the part that is wrong is both a clearer report and less incidental data.
 *
 * Declining the selection keeps the whole frame rather than losing the capture.
 */
export async function captureScreen({ segment = false } = {}) {
  if (!canCaptureScreen()) return null;

  let stream;
  try {
    stream = await navigator.mediaDevices.getDisplayMedia({
      video: { displaySurface: 'browser' },
      audio: false,
      preferCurrentTab: true, // Chromium hint; ignored elsewhere
    });
  } catch {
    return null; // user declined or the browser refused
  }

  try {
    const track = stream.getVideoTracks()[0];
    if (!track) return null;

    // ImageCapture where available: one frame, no video element, no playback.
    if (typeof window.ImageCapture === 'function') {
      try {
        const bitmap = await new window.ImageCapture(track).grabFrame();
        return await toImage(await finish(bitmap, bitmap.width, bitmap.height, segment));
      } catch {
        // fall through to the video path
      }
    }

    const video = document.createElement('video');
    video.srcObject = stream;
    video.muted = true;
    await video.play();
    await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));

    // finish() copies the frame to a canvas before it opens the region overlay,
    // so the pixels are safe no matter how long the user takes to choose.
    const canvas = await finish(video, video.videoWidth, video.videoHeight, segment);
    video.pause();
    video.srcObject = null;

    return await toImage(canvas);
  } finally {
    stream.getTracks().forEach((t) => t.stop()); // never leave the capture running
  }
}

/**
 * Snapshot the frame, optionally let the user crop it, and hand back a canvas.
 *
 * The frame is copied to a canvas first because the caller stops the media
 * stream as soon as it returns, and a MediaStream-backed bitmap is not
 * guaranteed to survive that. The user may sit on the selection overlay for a
 * while; nothing should depend on a live capture by then.
 */
async function finish(source, width, height, segment) {
  const full = drawScaled(source, width, height);
  if (!segment) return full;

  let area = null;
  try {
    area = await selectRegion(full);
  } catch {
    // A broken overlay must not cost the user their screenshot.
    return full;
  }

  return area ? cropTo(full, area) : full;
}

const ALLOWED_UPLOAD_TYPES = ['image/png', 'image/jpeg', 'image/webp', 'image/gif'];
const MAX_UPLOAD_BYTES = 8 * 1024 * 1024;

/** Validate a user-chosen screenshot file before it reaches the attachment API. */
export function validateScreenshotFile(file) {
  if (!file) return 'No file selected.';
  if (!ALLOWED_UPLOAD_TYPES.includes(file.type)) return 'Please attach a PNG, JPG, WEBP or GIF image.';
  if (file.size > MAX_UPLOAD_BYTES) return 'That image is larger than 8 MB. Please attach a smaller one.';
  return null;
}
