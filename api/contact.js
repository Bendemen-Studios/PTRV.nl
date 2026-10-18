const formidable = require('formidable');
const nodemailer = require('nodemailer');

const MAX_FILE_SIZE = 10 * 1024 * 1024;

function parseForm(req) {
  const form = formidable({
    multiples: false,
    maxFileSize: MAX_FILE_SIZE,
    maxTotalFileSize: MAX_FILE_SIZE,
    maxFiles: 1,
    allowEmptyFiles: false,
    filter: part => !part.mimetype || part.mimetype === 'application/octet-stream' || /stl|3mf/i.test(part.originalFilename || '')
  });
  return new Promise((resolve, reject) => {
    form.parse(req, (error, fields, files) => error ? reject(error) : resolve({ fields, files }));
  });
}

function first(value) {
  return Array.isArray(value) ? value[0] : value;
}

module.exports = async function handler(req, res) {
  if (req.method !== 'POST') {
    res.setHeader('Allow', 'POST');
    return res.status(405).json({ error: 'Methode niet toegestaan.' });
  }

  try {
    const { fields, files } = await parseForm(req);
    const name = String(first(fields.name) || '').trim();
    const email = String(first(fields.email) || '').trim();
    const phone = String(first(fields.phone) || '').trim();
    const idea = String(first(fields.idea) || '').trim();
    const file = first(files.file);

    if (!name || !email || !phone || !idea) {
      return res.status(400).json({ error: 'Vul alle verplichte velden in.' });
    }
    if (name.length > 120 || email.length > 254 || phone.length > 40 || idea.length > 6000) {
      return res.status(400).json({ error: 'Een of meer velden zijn te lang.' });
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      return res.status(400).json({ error: 'Vul een geldig e-mailadres in.' });
    }

    let attachments = [];
    if (file) {
      const originalName = file.originalFilename || '';
      const ext = originalName.toLowerCase().split('.').pop();
      if (!['stl', '3mf'].includes(ext) || file.size > MAX_FILE_SIZE) {
        return res.status(400).json({ error: 'Upload alleen een STL- of 3MF-bestand van maximaal 10 MB.' });
      }
      attachments = [{
        filename: originalName.replace(/[^a-zA-Z0-9._-]/g, '_').slice(0, 160),
        path: file.filepath,
        contentType: 'application/octet-stream'
      }];
    }

    const { SMTP_HOST, SMTP_PORT, SMTP_USER, SMTP_PASS, CONTACT_TO } = process.env;
    if (!SMTP_HOST || !SMTP_PORT || !SMTP_USER || !SMTP_PASS || !CONTACT_TO) {
      console.error('Contact form SMTP environment variables are not configured.');
      return res.status(503).json({ error: 'Het contactformulier is nog niet volledig ingesteld. Probeer het later opnieuw.' });
    }

    const transporter = nodemailer.createTransport({
      host: SMTP_HOST,
      port: Number(SMTP_PORT),
      secure: Number(SMTP_PORT) === 465,
      auth: { user: SMTP_USER, pass: SMTP_PASS }
    });

    await transporter.sendMail({
      from: { name: "Petrov's Workshop website", address: SMTP_USER },
      to: CONTACT_TO,
      replyTo: { name, address: email },
      subject: 'Nieuwe 3D-printaanvraag via PTRV.nl',
      text: `Nieuwe aanvraag via de website

Naam: ${name}
E-mail: ${email}
Telefoonnummer: ${phone}

Idee / opdracht:
${idea}`,
      attachments
    });

    return res.status(200).json({ ok: true });
  } catch (error) {
    if (error && (error.code === 1009 || /maxFileSize|larger than/i.test(error.message || ''))) {
      return res.status(413).json({ error: 'Het bestand is te groot. Maximaal 10 MB.' });
    }
    console.error('Contact form submission failed:', error.message);
    return res.status(500).json({ error: 'Versturen is niet gelukt. Probeer het later opnieuw.' });
  }
};
