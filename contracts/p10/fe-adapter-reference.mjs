/**
 * P10 revision 2 reference adapter. Pure validation/mapping only.
 * Not imported by production code. No HTTP, storage, auth clearing or mutation retry.
 * FE must integrate with its own request/caller/auth-generation guards and tests.
 */
export class P10ContractError extends Error {
  constructor(code) {
    super(code); // Never include a response, field value, credential or PII.
    this.name = 'P10ContractError';
    this.code = code;
  }
}
const fail = (code) => { throw new P10ContractError(code); };
const object = (x) => x !== null && typeof x === 'object' && !Array.isArray(x);
const integer = (x, minimum = 0) => Number.isSafeInteger(x) && x >= minimum;
const text = (x) => typeof x === 'string' && x.length > 0;
const uuid = (x) => typeof x === 'string' && x.length === 36 && /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i.test(x);
const phone = (x) => x === null || (typeof x === 'string' && x.length === 10 && /^0[0-9]{9}$/.test(x));
const own = (x, key) => Object.prototype.hasOwnProperty.call(x, key);

function envelope(response) {
  if (!object(response) || response.status !== 200 || !object(response.body)
      || response.body.success !== true || !object(response.body.meta)) {
    fail('P10_RESPONSE_NOT_CANONICAL');
  }
  return response.body;
}

/** Return the entire canonical profile AND metadata, not a projected name-only object. */
export function parseProfileResponse(response) {
  const body = envelope(response);
  const data = body.data;
  if (!object(data) || !uuid(data.user_id) || !integer(data.version, 1)
      || data.version >= 2147483647 || !text(data.display_name)
      || !own(data, 'phone') || !phone(data.phone)
      || !own(data, 'auth_session_id') || !(data.auth_session_id === null || uuid(data.auth_session_id))
      || !object(data.warehouse_context) || !text(data.warehouse_context.status)
      || !own(data.warehouse_context, 'error_code')
      || body.meta.profile_contract?.version !== 'auth-profile-p10-v1') {
    fail('P10_PROFILE_SCHEMA_MISMATCH');
  }
  // Strong server ETag only: do not manufacture it, fall back to an old version,
  // strip W/, or use an auth-session version as the profile version.
  if (typeof response.etag !== 'string' || !/^"[1-9][0-9]*"$/.test(response.etag)
      || response.etag !== `"${data.version}"`) {
    fail('P10_ETAG_MISMATCH');
  }
  return { profile: data, meta: body.meta, etag: response.etag };
}

/** UI name maps to display_name. Missing edit differs from explicit phone deletion. */
export function buildProfilePatch(profile, edits) {
  if (!object(profile) || !object(edits)
      || Object.keys(edits).some((key) => !['name', 'phone'].includes(key))) {
    fail('P10_UNSUPPORTED_EDIT');
  }
  const body = {};
  if (own(edits, 'name') && edits.name !== undefined) {
    if (typeof edits.name !== 'string') fail('P10_INVALID_NAME');
    const value = edits.name.trim();
    if ([...value].length < 1 || [...value].length > 255) fail('P10_INVALID_NAME');
    if (value !== profile.display_name) body.display_name = value;
  }
  if (own(edits, 'phone') && edits.phone !== undefined) {
    if (edits.phone !== null && typeof edits.phone !== 'string') fail('P10_INVALID_PHONE');
    const value = edits.phone === null || edits.phone.trim() === '' ? null : edits.phone.trim();
    if (!phone(value)) fail('P10_INVALID_PHONE');
    if (value !== profile.phone) body.phone = value;
  }
  if (Object.keys(body).length === 0) fail('P10_EMPTY_UPDATE');
  return body;
}

/** Preserve all pagination/current-family metadata and links. No UA/IP enrichment. */
export function parseSessionsResponse(response) {
  const body = envelope(response);
  const meta = body.meta;
  if (!Array.isArray(body.data) || !integer(meta.total) || !integer(meta.current_page, 1)
      || !integer(meta.per_page, 1) || meta.per_page > 100 || !integer(meta.last_page, 1)
      || body.data.length > meta.per_page || body.data.length > meta.total
      || (own(meta, 'current_session_id') && !uuid(meta.current_session_id))
      || meta.session_contract?.count_unit !== 'ACTIVE_AUTH_SESSION_FAMILY'
      || meta.session_contract?.target_id !== 'id'
      || meta.session_contract?.target_id_kind !== 'REFRESH_TOKEN_ROW_UUID') {
    fail('P10_SESSION_SCHEMA_MISMATCH');
  }
  const seen = new Set();
  for (const row of body.data) {
    if (!object(row) || !uuid(row.id) || !uuid(row.auth_session_id)
        || typeof row.is_current !== 'boolean' || typeof row.can_revoke !== 'boolean'
        || (row.is_current && row.can_revoke)
        || (own(row, 'session_id') && row.session_id !== row.auth_session_id)
        || seen.has(row.auth_session_id)) {
      fail('P10_SESSION_SCHEMA_MISMATCH');
    }
    seen.add(row.auth_session_id);
    if (own(meta, 'current_session_id') && (!uuid(meta.current_session_id)
        || row.is_current !== (row.auth_session_id === meta.current_session_id))) {
      fail('P10_SESSION_IDENTITY_MISMATCH');
    }
  }
  return { sessions: body.data, meta, links: body.links };
}

export function revokeTargetId(row) {
  if (!object(row) || !uuid(row.id) || row.is_current !== false || row.can_revoke !== true) {
    fail('P10_REVOKE_NOT_ALLOWED');
  }
  return row.id; // NEVER auth_session_id / session_id.
}

/** Successful HTTP data:null is not sufficient. No local state is cleared here. */
export function parseAuthResultResponse(response, operation) {
  const body = envelope(response);
  const result = body.meta.auth_result;
  if (body.data !== null || !object(result) || result.state !== 'SUCCEEDED'
      || result.durable_request_receipt !== false || result.request_reconcile_supported !== false) {
    fail('P10_AUTH_RESULT_MISMATCH');
  }
  if (operation === 'LOGOUT') {
    if (!['CURRENT_AUTH_SESSION_FAMILY', 'CURRENT_ACCESS_TOKEN_ONLY'].includes(result.scope)
        || result.business_drafts_deleted !== false || result.work_shift_ended !== false) {
      fail('P10_AUTH_RESULT_MISMATCH');
    }
  } else if (operation === 'CHANGE_PASSWORD') {
    if (result.relogin_required !== true || result.session_policy !== 'RELOGIN_ALL_REFRESH_FAMILIES_REVOKED') {
      fail('P10_AUTH_RESULT_MISMATCH');
    }
  } else {
    fail('P10_UNSUPPORTED_OPERATION');
  }
  return { data: null, meta: body.meta, authResult: result };
}

/**
 * Ticket values come from the FE's authenticated caller context, not a response.
 * authGeneration MUST increment on logout/new login, including same actor.
 * requestSequence MUST increment per caller request. Neither is a server receipt.
 */
export function isCurrentResponse(ticket, current) {
  const valid = (x) => object(x) && uuid(x.actorId)
    && own(x, 'authSessionId') && (x.authSessionId === null || uuid(x.authSessionId))
    && integer(x.authGeneration) && integer(x.requestSequence) && text(x.caller);
  return valid(ticket) && valid(current)
    && ['actorId', 'authSessionId', 'authGeneration', 'requestSequence', 'caller']
      .every((key) => ticket[key] === current[key]);
}
