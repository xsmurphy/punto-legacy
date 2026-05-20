/**
 * Cliente HTTP para los endpoints canónicos /API/v1/items.
 *
 * Wraps fetch() con:
 *   - credentials: 'include' (cookie de sesión del panel)
 *   - Content-Type: application/json en mutations
 *   - Desempaqueta el envelope canónico { ok, data, error }
 *   - Tira con un Error tipado si ok===false (mensaje + code + details)
 *
 * Uso:
 *   const { items, total } = await itemsApi.list({ limit: 50, archived: 1 });
 *   const item = await itemsApi.get(uuid);
 *   const created = await itemsApi.create({ type: 'discount' });
 *   await itemsApi.update(uuid, { itemName: 'Nuevo' });
 *   await itemsApi.archive(uuid);
 *
 * Sub-recurso depósitos:
 *   const { locations } = await itemsApi.locations.list(uuid);
 *   await itemsApi.locations.sync(uuid, { locationIds: [...], default: '...' });
 */

(function (window) {
	'use strict';

	const BASE = '/API/v1/items';

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

	const itemsApi = {
		ApiError: ApiError,

		// GET /API/v1/items?limit=50&offset=0&archived=0&q=&type=
		list: function (opts) {
			return request('GET', '', opts || {});
		},

		// GET /API/v1/items?id=<uuid>
		get: function (id) {
			return request('GET', '', { id: id });
		},

		// POST /API/v1/items   body: { type?, ...campos }
		create: function (body) {
			return request('POST', '', null, body || {});
		},

		// PUT /API/v1/items?id=<uuid>  body: { ...campos }
		update: function (id, body) {
			return request('PUT', '', { id: id }, body || {});
		},

		// DELETE /API/v1/items?id=<uuid>
		archive: function (id) {
			return request('DELETE', '', { id: id });
		},

		// PUT itemStatus=1 — reactiva un item archivado.
		unarchive: function (id) {
			return request('PUT', '', { id: id }, { itemStatus: 1 });
		},

		// Helpers bulk — el backend aún no tiene endpoints multi, lo hacemos
		// en paralelo. Devuelve { successes: number, errors: ApiError[] }.
		bulkArchive: function (ids) {
			return _bulk(ids, function (id) { return itemsApi.archive(id); });
		},
		bulkUnarchive: function (ids) {
			return _bulk(ids, function (id) { return itemsApi.unarchive(id); });
		},

		locations: {
			// GET /API/v1/items?id=<uuid>&resource=locations
			list: function (id) {
				return request('GET', '', { id: id, resource: 'locations' });
			},

			// PUT /API/v1/items?id=<uuid>&resource=locations
			// body: { locationIds: [...], default?: '...' }
			sync: function (id, body) {
				return request('PUT', '', { id: id, resource: 'locations' }, body || {});
			},
		},
	};

	window.itemsApi = itemsApi;
})(window);
