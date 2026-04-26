/**
 * WorkToGo — API Layer
 * All HTTP calls go through this module.
 * Handles auth headers, errors, and JSON parsing uniformly.
 */

const API = (() => {
  /** Low-level fetch wrapper */
  async function request(method, path, body = null) {
    const url = `${CONFIG.BASE_URL}${path}`;
    const opts = {
      method,
      headers: Auth.headers(),
    };
    if (body) opts.body = JSON.stringify(body);

    try {
      const res = await fetch(url, opts);

      // Session expired
      if (res.status === 401) {
        Auth.logout();
        return { ok: false, status: 401, error: "Session expired. Please login again." };
      }

      let data;
      try { data = await res.json(); } catch { data = {}; }

      return { ok: res.ok, status: res.status, data };
    } catch (err) {
      return { ok: false, status: 0, error: err.message || "Network error" };
    }
  }

  const get    = (path)        => request("GET",    path);
  const post   = (path, body)  => request("POST",   path, body);
  const put    = (path, body)  => request("PUT",    path, body);
  const patch  = (path, body)  => request("PATCH",  path, body);
  const del    = (path)        => request("DELETE", path);

  /* ────────────────────────────────────────────────
     AUTH
  ──────────────────────────────────────────────── */
  const Auth_API = {
    login: (email, password) => post("/api/auth/login", { email, password }),
  };

  /* ────────────────────────────────────────────────
     INTENT PIPELINE
  ──────────────────────────────────────────────── */
  const intent = (name, payload = {}) => post("", { intent: name, payload });

  /* ────────────────────────────────────────────────
     DASHBOARD
  ──────────────────────────────────────────────── */
  const Dashboard = {
    getSummary: () => intent("vendor:get_summary"),
  };

  /* ────────────────────────────────────────────────
     PROFILE
  ──────────────────────────────────────────────── */
  const Profile = {
    get:    ()       => intent("vendor:get_profile"),
    update: (data)   => intent("vendor:update_profile", data),
  };

  /* ────────────────────────────────────────────────
     PRODUCTS  (vendor_shopping)
  ──────────────────────────────────────────────── */
  const Products = {
    list:   ()           => intent("vendor:list_products"),
    get:    (id)         => intent("vendor:get_product", { id }),
    create: (data)       => intent("vendor:create_product", data),
    update: (id, data)   => intent("vendor:update_product", { id, ...data }),
    delete: (id)         => intent("vendor:delete_product", { id }),
  };

  /* ────────────────────────────────────────────────
     ORDERS  (vendor_shopping)
  ──────────────────────────────────────────────── */
  const Orders = {
    list:         ()             => intent("vendor:list_orders"),
    get:          (id)           => intent("vendor:get_order", { id }),
    updateStatus: (id, status)   => intent("vendor:update_order_status", { id, status }),
  };

  /* ────────────────────────────────────────────────
     JOBS  (vendor_service)
  ──────────────────────────────────────────────── */
  const Jobs = {
    list:         ()             => intent("vendor:list_jobs"),
    get:          (id)           => intent("vendor:get_job", { id }),
    accept:       (id)           => intent("vendor:update_job_status", { id, status: "confirmed" }),
    reject:       (id)           => intent("vendor:update_job_status", { id, status: "cancelled" }),
    updateStatus: (id, status)   => intent("vendor:update_job_status", { id, status }),
  };

  return { get, post, put, patch, del, intent, Auth: Auth_API, Dashboard, Profile, Products, Orders, Bookings: Jobs, Jobs };
})();
