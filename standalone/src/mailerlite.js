/** Upserts a subscriber in MailerLite (connect API). Returns true on success. */
export async function mailerliteSync(env, groupId, { email, name, zone }) {
  if (!env.MAILERLITE_API_KEY) return false;
  const body = { email, fields: { name: name || null, city: 'New York' } };
  if (zone) body.fields.company = zone;
  if (groupId) body.groups = [groupId];
  try {
    const res = await fetch('https://connect.mailerlite.com/api/subscribers', {
      method: 'POST',
      headers: {
        Authorization: `Bearer ${env.MAILERLITE_API_KEY}`,
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      body: JSON.stringify(body),
    });
    if (res.ok) return true;
    console.error('MailerLite sync error', res.status, await res.text());
  } catch (err) {
    console.error('MailerLite sync failed', err);
  }
  return false;
}
