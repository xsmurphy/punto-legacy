/**
 * Cliente HTTP para los endpoints canónicos /API/v1/contacts.
 *
 * Wraps fetch() con:
 *   - credentials: 'include' (cookie de sesión del panel)
 *   - Content-Type: application/json en mutations
 *   - Desempaqueta el envelope canónico { ok, data, error }
 *   - Tira con un Error tipado si ok===false (mensaje + code + details)
 *
 * Uso:
 *   const { contacts, total } = await contactsApi.list({ limit: 50, q: 'juan' });
 *   const c = await contactsApi.get(uuid);
 *   const created = await contactsApi.create({ name: 'Juan', tin: '80012345-6' });
 *   await contactsApi.update(uuid, { phone: '0981...' });
 *   await contactsApi.archive(uuid);
 *
 * Sub-recurso direcciones:
 *   const { addresses } = await contactsApi.addresses.list(uuid);
 */

(function (window) {
	'use strict';

	const BASE = '/API/v1/contacts';

	function buildQuery(params) {
		if (!params) return '';
		const usp = new URLSearchParams();
		for (const k in params) {
			if (params[k] !== undefined && params[k] !== null && params[k] !== '') {
				usp.append(k, params[k]);
			}
		}
		const s = usp.toString();
		return s ? '?' + s : '';
	}

	class ApiError extends Error {
		constructor(message, code, details) {
			super(message);
			this.name = 'ApiError';
			this.code = code;
			this.details = details || [];
		}
	}

	async function request(method, path, query, body) {
		const url = BASE + (path || '') + buildQuery(query);
		const opts = {
			method: method,
			credentials: 'include',
			headers: {},
		};
		if (body !== undefined) {
			opts.headers['Content-Type'] = 'application/json';
			opts.body = JSON.stringify(body);
		}

		let res;
		try {
			res = await fetch(url, opts);
		} catch (e) {
			throw new ApiError('Network error: ' + e.message, 0);
		}

		let json;
		try {
			json = await res.json();
		} catch (e) {
			throw new ApiError('Respuesta inválida del servidor', res.status);
		}

		if (json && json.ok === true) {
			return json.data;
		}
		const err = (json && json.error) || {};
		throw new ApiError(err.message || 'Error desconocido', err.code || res.status, err.details);
	}

	async function _bulk(ids, fn) {
		const results = await Promise.allSettled(ids.map(fn));
		return {
			successes: results.filter(r => r.status === 'fulfilled').length,
			errors:    results.filter(r => r.status === 'rejected').map(r => r.reason),
		};
	}

	const contactsApi = {
		ApiError: ApiError,

		// GET /API/v1/contacts?limit=50&offset=0&q=&status=
		list: function (opts) {
			return request('GET', '', opts || {});
		},

		// GET /API/v1/contacts?id=<uuid>
		get: function (id) {
			return request('GET', '', { id: id });
		},

		// POST /API/v1/contacts   body: { fiscalName|name, tin, ci, phone, ... }
		create: function (body) {
			return request('POST', '', null, body || {});
		},

		// PUT /API/v1/contacts?id=<uuid>  body: { ...campos }
		update: function (id, body) {
			return request('PUT', '', { id: id }, body || {});
		},

		// DELETE /API/v1/contacts?id=<uuid>  → soft-delete (contactStatus = 0)
		archive: function (id) {
			return request('DELETE', '', { id: id });
		},

		// PUT contactStatus=1 — reactiva un contacto archivado.
		unarchive: function (id) {
			return request('PUT', '', { id: id }, { status: 1 });
		},

		// Helpers bulk — el backend aún no tiene endpoints multi, lo hacemos
		// en paralelo. Devuelve { successes: number, errors: ApiError[] }.
		bulkArchive: function (ids) {
			return _bulk(ids, function (id) { return contactsApi.archive(id); });
		},

		addresses: {
			// GET /API/v1/contacts?id=<uuid>&resource=addresses
			list: function (id) {
				return request('GET', '', { id: id, resource: 'addresses' });
			},
		},
	};

	window.contactsApi = contactsApi;
})(window);
