/**
 * WorkToGo — Commerce Storefront API client (Phase 4)
 * COMMERCE-SCOPED. Talks only to the /api/shop/* adapter (which reuses the
 * never-touch ProductService/CartService/OrderService). Kept separate from the
 * service app's api.js so commerce stays self-contained.
 */
const _base = () => (window.CONFIG && CONFIG.BASE_URL) ? CONFIG.BASE_URL : "";
const _token = () => localStorage.getItem((window.CONFIG && CONFIG.TOKEN_KEY) || "wtg_token") || "";

function _qs(o = {}) {
  const e = Object.entries(o).filter(([, v]) => v !== "" && v != null);
  const s = new URLSearchParams(e).toString();
  return s ? "?" + s : "";
}

async function _req(method, path, body) {
  let res;
  try {
    res = await fetch(`${_base()}/api/shop${path}`, {
      method,
      headers: {
        "Content-Type": "application/json",
        ...(_token() ? { Authorization: `Bearer ${_token()}` } : {}),
      },
      ...(body ? { body: JSON.stringify(body) } : {}),
    });
  } catch (e) {
    return { ok: false, status: 0, error: "You appear to be offline. Check your connection." };
  }
  let json = {};
  try { json = await res.json(); } catch {}
  if (!res.ok || json.success === false) {
    return { ok: false, status: res.status, error: json.message || json.error || "Something went wrong", data: json.data ?? null };
  }
  return { ok: true, status: res.status, data: json.data ?? json };
}

export const ShopAPI = {
  meta:           ()               => _req("GET",  "/meta"),
  stores:         (params = {})    => _req("GET",  "/stores" + _qs(params)),
  products:       (params = {})    => _req("GET",  "/products" + _qs(params)),
  product:        (id)             => _req("GET",  `/products/${id}`),
  // PB4 paint storefront (grouped by parent; variant + pack selector; one server price).
  paintHome:      (params = {})    => _req("GET",  "/paint/home" + _qs(params)),
  paintProduct:   (id, pincode)    => _req("GET",  `/paint/product/${id}` + _qs({ pincode })),
  // PB14 serviceability
  serviceability: (pincode)        => _req("GET",  "/serviceability" + _qs({ pincode })),
  serviceRequest: (pincode, phone) => _req("POST", "/service-request", { pincode, phone }),
  cart:           ()               => _req("GET",  "/cart"),
  addToCart:      (product_id, quantity = 1) => _req("POST", "/cart", { product_id, quantity, op: "add" }),
  setQty:         (product_id, quantity)     => _req("POST", "/cart", { product_id, quantity, op: "set" }),
  removeFromCart: (product_id)     => _req("POST", "/cart/remove", { product_id }),
  checkout:       (payload)        => _req("POST", "/checkout", payload),
  orders:         ()               => _req("GET",  "/orders"),
  order:          (id)             => _req("GET",  `/orders/${id}`),
  cancelOrder:    (id)             => _req("POST", `/orders/${id}/cancel`),
  reorder:        (id)             => _req("POST", `/orders/${id}/reorder`),
  // Native delivery engine (own scope): fee by distance + live tracking.
  deliveryQuote:  (dropLat, dropLng) => _req("GET", "/delivery-quote" + _qs({ drop_lat: dropLat, drop_lng: dropLng })),
  deliveryTrack:  async (orderId) => {
    try {
      const res = await fetch(`${_base()}/api/delivery/track/${orderId}`, { headers: { ..._token() ? { Authorization: `Bearer ${_token()}` } : {} } });
      const j = await res.json().catch(() => ({}));
      return (res.ok && j.success !== false) ? { ok: true, data: j.data } : { ok: false, error: j.message };
    } catch { return { ok: false, error: "offline" }; }
  },
};
