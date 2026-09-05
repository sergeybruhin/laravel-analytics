/**
 * Reference frontend client for sergeybruhin/laravel-analytics.
 *
 * This file is intentionally generic — it knows nothing about any specific
 * host project's own CustomEvents (e.g. a storefront's "cartItemAdded"), UTM
 * capture utility, or CSRF helper. Copy it into your own Vite build (e.g.
 * resources/views/web/plugins/tracker.ts) and adapt the two spots marked
 * "integration point" below to your project's existing conventions. Wiring
 * project-specific zero-touch instrumentation (listening for your app's own
 * CustomEvents and calling Analytics.track() from them) belongs in your
 * adapted copy, not in this reference file — that keeps this package usable
 * by any project, not just ones shaped like the one it was first built for.
 */

type Hit =
  | { type: 'pageview'; url: string; path: string; title: string; duration_ms?: number }
  | { type: 'event'; dedup_key: string; name: string; properties?: Record<string, unknown>; value?: number };

interface UtmMeta {
  source: string | null;
  medium: string | null;
  campaign: string | null;
  content: string | null;
  term: string | null;
  click_ids: Record<string, string>;
  referrer: string | null;
}

const COLLECT_URL = '/api/analytics/collect';
const VISITOR_ID_KEY = 'qskn_vid';
const SESSION_ID_KEY = 'qskn_analytics_sid';
const FLUSH_INTERVAL_MS = 5000;
const MAX_BATCH_SIZE = 20;

let queue: Hit[] = [];
let openPageview: { hit: Extract<Hit, { type: 'pageview' }>; startedAt: number } | null = null;
let pendingIdentify: { external_id: string; traits?: Record<string, unknown> } | null = null;
let landingSent = false;

function uuid(): string {
  return crypto.randomUUID();
}

function getVisitorId(): string {
  let id = localStorage.getItem(VISITOR_ID_KEY);
  if (!id) {
    id = uuid();
    localStorage.setItem(VISITOR_ID_KEY, id);
  }
  return id;
}

function getSessionId(): string {
  let id = sessionStorage.getItem(SESSION_ID_KEY);
  if (!id) {
    id = uuid();
    sessionStorage.setItem(SESSION_ID_KEY, id);
  }
  return id;
}

/**
 * Integration point 1 — UTM/click-id extraction.
 *
 * This reference implementation reads the current URL's query string
 * directly. A host project that already persists first-touch/last-touch
 * attribution across visits (e.g. iqskin-site's
 * resources/views/web/plugins/utm-meta.ts) should replace this function's
 * body with a call into that existing utility instead, so the two never
 * disagree about what "the current attribution" is.
 */
function getUtmMeta(): UtmMeta {
  const params = new URLSearchParams(location.search);
  const clickIds: Record<string, string> = {};

  for (const key of ['gclid', 'fbclid', 'yclid']) {
    const value = params.get(key);
    if (value) clickIds[key] = value;
  }

  return {
    source: params.get('utm_source'),
    medium: params.get('utm_medium'),
    campaign: params.get('utm_campaign'),
    content: params.get('utm_content'),
    term: params.get('utm_term'),
    click_ids: clickIds,
    referrer: document.referrer || null,
  };
}

/**
 * Integration point 2 — delivery.
 *
 * sendBeacon can't set a CSRF header and the collect endpoint is
 * CSRF-exempt for exactly that reason (see the package README), so this
 * reference implementation does not attach one. If your host wraps every
 * other endpoint's fetch calls in a CSRF-token helper, that helper should
 * NOT be reused here.
 */
function send(body: string, useBeacon: boolean): void {
  if (useBeacon && navigator.sendBeacon) {
    const blob = new Blob([body], { type: 'application/json' });
    if (navigator.sendBeacon(COLLECT_URL, blob)) return;
  }

  fetch(COLLECT_URL, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body,
    keepalive: true,
  }).catch(() => {
    // Best-effort by design. A dropped analytics hit is not worth retrying
    // client-side — the visitor's next pageview/event will succeed instead.
  });
}

function flush(useBeacon = false): void {
  if (openPageview) {
    openPageview.hit.duration_ms = Date.now() - openPageview.startedAt;
  }

  if (queue.length === 0) return;

  const utm = getUtmMeta();
  const envelope = {
    visitor_id: getVisitorId(),
    session_id: getSessionId(),
    landing: !landingSent,
    referrer: utm.referrer,
    utm: {
      source: utm.source,
      medium: utm.medium,
      campaign: utm.campaign,
      content: utm.content,
      term: utm.term,
    },
    click_ids: utm.click_ids,
    identify: pendingIdentify ?? undefined,
    hits: queue,
  };

  // Cleared before the network call, not after: a flush is fire-and-forget,
  // and clearing first guarantees a hit is never included in two envelopes
  // (there is no dedup_key on pageviews the way there is on events).
  queue = [];
  openPageview = null;
  pendingIdentify = null;
  landingSent = true;

  send(JSON.stringify(envelope), useBeacon);
}

function enqueue(hit: Hit): void {
  queue.push(hit);
  if (queue.length >= MAX_BATCH_SIZE) flush();
}

function trackPageview(): void {
  const hit: Extract<Hit, { type: 'pageview' }> = {
    type: 'pageview',
    url: location.href,
    path: location.pathname,
    title: document.title,
  };
  openPageview = { hit, startedAt: Date.now() };
  enqueue(hit);
}

function track(name: string, properties: Record<string, unknown> = {}, value?: number): void {
  enqueue({ type: 'event', dedup_key: uuid(), name, properties, value });
}

function identify(externalId: string, traits: Record<string, unknown> = {}): void {
  pendingIdentify = { external_id: externalId, traits };
  // Riding along on a lightweight event guarantees delivery on the next
  // flush even if the page fires no other hits before the visitor leaves —
  // the collect endpoint requires at least one hit per request.
  track('identify');
}

function init(): void {
  trackPageview();

  setInterval(() => flush(false), FLUSH_INTERVAL_MS);

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'hidden') flush(true);
  });
  window.addEventListener('pagehide', () => flush(true));
}

declare global {
  interface Window {
    Analytics: {
      track: typeof track;
      identify: typeof identify;
      trackPageview: typeof trackPageview;
    };
  }
}

window.Analytics = { track, identify, trackPageview };

init();
