import { HttpError } from './http.js';

const JWKS_TTL_MS = 60 * 60 * 1000;
const jwksCache = new Map();

function teamOrigin(env) {
  const domain = (env.ACCESS_TEAM_DOMAIN || '').trim().replace(/^https?:\/\//, '').replace(/\/+$/, '');
  return domain ? `https://${domain}` : '';
}

function b64urlDecode(input) {
  const b64 = input.replace(/-/g, '+').replace(/_/g, '/').padEnd(Math.ceil(input.length / 4) * 4, '=');
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return bytes;
}

async function getSigningKeys(issuer, forceRefresh = false) {
  const cached = jwksCache.get(issuer);
  if (!forceRefresh && cached && Date.now() - cached.at < JWKS_TTL_MS) return cached.keys;
  const res = await fetch(`${issuer}/cdn-cgi/access/certs`);
  if (!res.ok) throw new HttpError(503, 'Could not fetch Cloudflare Access signing keys.');
  const { keys = [] } = await res.json();
  jwksCache.set(issuer, { keys, at: Date.now() });
  return keys;
}

async function verifyAccessJwt(token, env) {
  const issuer = teamOrigin(env);
  const parts = token.split('.');
  if (parts.length !== 3) throw new HttpError(401, 'Malformed Access token.');
  const [h, p, s] = parts;
  const header = JSON.parse(new TextDecoder().decode(b64urlDecode(h)));
  const payload = JSON.parse(new TextDecoder().decode(b64urlDecode(p)));
  if (header.alg !== 'RS256') throw new HttpError(401, 'Unsupported Access token algorithm.');

  let jwk = (await getSigningKeys(issuer)).find((k) => k.kid === header.kid);
  if (!jwk) jwk = (await getSigningKeys(issuer, true)).find((k) => k.kid === header.kid);
  if (!jwk) throw new HttpError(401, 'Unknown Access signing key.');

  const key = await crypto.subtle.importKey(
    'jwk',
    { kty: jwk.kty, n: jwk.n, e: jwk.e, alg: 'RS256', ext: true },
    { name: 'RSASSA-PKCS1-v1_5', hash: 'SHA-256' },
    false,
    ['verify'],
  );
  const valid = await crypto.subtle.verify(
    'RSASSA-PKCS1-v1_5',
    key,
    b64urlDecode(s),
    new TextEncoder().encode(`${h}.${p}`),
  );
  if (!valid) throw new HttpError(401, 'Invalid Access token signature.');

  const now = Math.floor(Date.now() / 1000);
  const aud = Array.isArray(payload.aud) ? payload.aud : [payload.aud];
  if (!aud.includes(env.ACCESS_AUD)) throw new HttpError(401, 'Access token audience mismatch.');
  if (payload.iss !== issuer) throw new HttpError(401, 'Access token issuer mismatch.');
  if (typeof payload.exp !== 'number' || payload.exp < now) throw new HttpError(401, 'Access token expired.');
  if (typeof payload.nbf === 'number' && payload.nbf > now + 60) throw new HttpError(401, 'Access token not yet valid.');
  if (!payload.email) throw new HttpError(401, 'Access token has no email (service tokens are not supported).');
  return String(payload.email).toLowerCase();
}

function tokenFromRequest(request) {
  const header = request.headers.get('Cf-Access-Jwt-Assertion');
  if (header) return header;
  const cookie = request.headers.get('Cookie') || '';
  const match = cookie.match(/(?:^|;\s*)CF_Authorization=([^;]+)/);
  return match ? match[1] : null;
}

function isLocalhost(request) {
  const { hostname } = new URL(request.url);
  return hostname === 'localhost' || hostname === '127.0.0.1' || hostname === '[::1]';
}

async function authenticatedEmail(request, env) {
  if (teamOrigin(env)) {
    if (!env.ACCESS_AUD) throw new HttpError(500, 'ACCESS_AUD is not configured.');
    const token = tokenFromRequest(request);
    if (!token) throw new HttpError(401, 'Not signed in. Open /admin through Cloudflare Access.');
    return verifyAccessJwt(token, env);
  }
  if (env.DEV_ADMIN_EMAIL && isLocalhost(request)) {
    return String(env.DEV_ADMIN_EMAIL).toLowerCase();
  }
  throw new HttpError(500, 'Cloudflare Access is not configured (set ACCESS_TEAM_DOMAIN and ACCESS_AUD).');
}

export async function requireUser(request, env) {
  const email = await authenticatedEmail(request, env);
  const bootstrapAdmins = (env.ADMIN_EMAILS || '')
    .split(',')
    .map((s) => s.trim().toLowerCase())
    .filter(Boolean);
  if (bootstrapAdmins.includes(email) || (env.DEV_ADMIN_EMAIL && email === String(env.DEV_ADMIN_EMAIL).toLowerCase() && !teamOrigin(env))) {
    return { email, role: 'admin' };
  }
  const row = await env.DB.prepare('SELECT role FROM admin_users WHERE email = ?1').bind(email).first();
  if (!row) throw new HttpError(403, `${email} is not authorized to use this panel.`);
  return { email, role: row.role };
}

export function requireAdmin(user) {
  if (user.role !== 'admin') throw new HttpError(403, 'Administrator access required.');
}
