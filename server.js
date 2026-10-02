// Basit e-Arşiv fatura paneli.
// Kullanım: npm start  ->  http://localhost:3000
// GİB test portalı için: FATURA_TEST=1 npm start
//
// WhatsApp telefon doğrulaması (cevaplama.com 2FA altyapısı) için ortam değişkenleri:
//   WA_OTP_URL                Sunucudaki wa-otp.php adresi (HTTPS)
//   WA_OTP_KEY                wa-otp.php ile paylaşılan gizli anahtar
//   COOKIE_SECRET             Telefon çerezini imzalamak için sabit gizli anahtar
//   COOKIE_SECURE=1           Çerezleri yalnızca HTTPS üzerinden gönder

const http = require("http");
const fs = require("fs");
const path = require("path");
const crypto = require("crypto");
const fetch = require("isomorphic-fetch");
const fatura = require("./index");

const PORT = process.env.PORT || 3000;
const HOST = process.env.HOST || "127.0.0.1";
const SESSION_TTL = 30 * 60 * 1000;
const PHONE_COOKIE_TTL = 365 * 24 * 60 * 60;
const WHATSAPP_CODE_TTL = 5 * 60 * 1000;
const WHATSAPP_RESEND_WAIT = 60 * 1000;
const WHATSAPP_MAX_ATTEMPTS = 5;
const COOKIE_SECURE = process.env.COOKIE_SECURE === "1" ? "; Secure" : "";

// Sabit bir anahtar verilmezse sunucu her açıldığında kayıtlı telefon çerezleri geçersiz olur.
const COOKIE_SECRET =
  process.env.COOKIE_SECRET || crypto.randomBytes(32).toString("hex");

if (process.env.FATURA_TEST === "1") {
  fatura.enableTestMode();
}

// sid -> { token, lastUsed, whatsapp? }. Şifre saklanmaz, yalnızca GİB token'ı tutulur.
const sessions = new Map();

class HttpError extends Error {
  constructor(status, message) {
    super(message);
    this.status = status;
  }
}

// Yardımcılar

function round(number) {
  return Math.round((number + Number.EPSILON) * 100) / 100;
}

function istanbulNow() {
  const parts = new Intl.DateTimeFormat("tr-TR", {
    timeZone: "Europe/Istanbul",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    second: "2-digit",
    hour12: false
  }).formatToParts(new Date());
  const get = type => parts.find(part => part.type === type).value;
  return {
    date: `${get("day")}/${get("month")}/${get("year")}`,
    time: `${get("hour")}:${get("minute")}:${get("second")}`
  };
}

function isDate(value) {
  return /^\d{2}\/\d{2}\/\d{4}$/.test(value || "");
}

function parseCookies(req) {
  return Object.fromEntries(
    (req.headers.cookie || "")
      .split(";")
      .map(cookie => cookie.trim().split("="))
      .filter(([key]) => key)
  );
}

function sendJSON(res, status, data, headers = {}) {
  res.writeHead(status, {
    "content-type": "application/json; charset=utf-8",
    ...headers
  });
  res.end(JSON.stringify(data));
}

async function readBody(req) {
  let body = "";
  for await (const chunk of req) {
    body += chunk;
    if (body.length > 1e6) throw new HttpError(413, "İstek çok büyük.");
  }
  try {
    return body ? JSON.parse(body) : {};
  } catch (e) {
    throw new HttpError(400, "Geçersiz istek.");
  }
}

function maskPhone(phone) {
  return String(phone).replace(/^(.*)(\d{2})$/, (_, a, b) =>
    a.replace(/\d/g, "*") + b
  );
}

// 05321234567, 5321234567, +90 532 123 45 67 -> 905321234567
function normalizePhone(phone) {
  let digits = String(phone || "").replace(/\D/g, "");
  if (digits.startsWith("0")) digits = digits.slice(1);
  if (digits.length === 10) digits = `90${digits}`;
  if (!/^905\d{9}$/.test(digits)) {
    throw new HttpError(400, "Geçerli bir cep telefonu numarası girin.");
  }
  return digits;
}

function hmac(value) {
  return crypto
    .createHmac("sha256", COOKIE_SECRET)
    .update(String(value))
    .digest("hex");
}

function safeEqual(a, b) {
  const x = Buffer.from(String(a));
  const y = Buffer.from(String(b));
  return x.length === y.length && crypto.timingSafeEqual(x, y);
}

// WhatsApp ile doğrulanmış telefon çerezi: "<telefon>.<imza>"
function getVerifiedPhone(req) {
  const [phone, signature] = decodeURIComponent(
    parseCookies(req).wa_phone || ""
  ).split(".");
  return phone && signature && safeEqual(hmac(`wa_phone:${phone}`), signature)
    ? phone
    : null;
}

function whatsappConfigured() {
  return Boolean(process.env.WA_OTP_URL && process.env.WA_OTP_KEY);
}

// Kodu, cevaplama.com 2FA altyapısını kullanan sunucudaki uç noktaya iletir.
// Uç nokta yalnızca telefon ve kodu kabul eder; mesaj metnini kendisi oluşturur.
async function sendWhatsAppCode(phone, code) {
  const response = await fetch(process.env.WA_OTP_URL, {
    method: "POST",
    headers: {
      authorization: `Bearer ${process.env.WA_OTP_KEY}`,
      "content-type": "application/json"
    },
    body: JSON.stringify({ phone, code })
  });
  const json = await response.json().catch(() => ({}));
  if (!response.ok || !json.ok) {
    console.error("WhatsApp gönderim hatası:", response.status, json.error);
    throw new HttpError(502, "WhatsApp mesajı gönderilemedi.");
  }
}

function getSession(req) {
  const sid = parseCookies(req).sid;
  const session = sid && sessions.get(sid);
  if (!session || Date.now() - session.lastUsed > SESSION_TTL) {
    if (sid) sessions.delete(sid);
    throw new HttpError(401, "Oturum süresi doldu, lütfen tekrar giriş yapın.");
  }
  session.lastUsed = Date.now();
  return { sid, ...session };
}

// Formdan gelen kalemlerden fatura toplamlarını hesaplar.
function buildInvoice(body) {
  const items = (body.items || [])
    .filter(item => item && String(item.name || "").trim())
    .map(item => {
      const quantity = Number(item.quantity) || 0;
      const unitPrice = Number(item.unitPrice) || 0;
      const VATRate = Number(item.VATRate) || 0;
      const price = round(quantity * unitPrice);
      return {
        name: String(item.name).trim(),
        quantity,
        unitType: item.unitType || "C62",
        unitPrice,
        price,
        VATRate,
        VATAmount: round((price * VATRate) / 100)
      };
    });

  if (!items.length) throw new HttpError(400, "En az bir kalem ekleyin.");
  if (items.some(item => item.quantity <= 0 || item.unitPrice < 0)) {
    throw new HttpError(400, "Miktar ve birim fiyatı kontrol edin.");
  }
  if (!/^\d{10,11}$/.test(body.taxIDOrTRID || "")) {
    throw new HttpError(400, "VKN 10, TCKN 11 haneli olmalıdır.");
  }
  if (!body.title && !(body.name && body.surname)) {
    throw new HttpError(400, "Alıcı unvanı ya da adı-soyadı gerekli.");
  }

  const now = istanbulNow();
  const grandTotal = round(items.reduce((sum, item) => sum + item.price, 0));
  const totalVAT = round(items.reduce((sum, item) => sum + item.VATAmount, 0));

  return {
    date: isDate(body.date) ? body.date : now.date,
    time: now.time,
    taxIDOrTRID: body.taxIDOrTRID,
    taxOffice: body.taxOffice || "",
    title: body.title || "",
    name: body.name || "",
    surname: body.surname || "",
    fullAddress: body.fullAddress || "",
    district: body.district || "",
    city: body.city || " ",
    email: body.email || "",
    items,
    totalVAT,
    grandTotal,
    grandTotalInclVAT: round(grandTotal + totalVAT),
    paymentTotal: round(grandTotal + totalVAT)
  };
}

// GİB'den tarih aralığındaki faturaları alıp verilen ETTN'leri seçer.
// İstemciden gelen fatura nesnelerine güvenmek yerine GİB'deki güncel kayıt kullanılır.
async function findInvoicesByETTN(token, { startDate, endDate, ettns }) {
  if (!isDate(startDate) || !isDate(endDate)) {
    throw new HttpError(400, "Tarih aralığı gerekli.");
  }
  const invoices = await fatura.getAllInvoicesByDateRange(token, {
    startDate,
    endDate
  });
  const found = (invoices || []).filter(invoice =>
    (ettns || []).includes(invoice.ettn)
  );
  if (!found.length) throw new HttpError(404, "Seçilen fatura bulunamadı.");
  return found;
}

// Rotalar

const routes = {
  "POST /api/login": async (req, res) => {
    const { username, password } = await readBody(req);
    if (!username || !password) {
      throw new HttpError(400, "Kullanıcı kodu ve şifre gerekli.");
    }
    const token = await fatura.getToken(
      encodeURIComponent(username.trim()),
      encodeURIComponent(password)
    );
    const sid = crypto.randomBytes(32).toString("hex");
    sessions.set(sid, { token, lastUsed: Date.now() });
    const user = await fatura.getUserData(token).catch(() => null);
    sendJSON(
      res,
      200,
      { user, testMode: process.env.FATURA_TEST === "1" },
      {
        "set-cookie": `sid=${sid}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${SESSION_TTL /
          1000}${COOKIE_SECURE}`
      }
    );
  },

  "POST /api/logout": async (req, res) => {
    const sid = parseCookies(req).sid;
    const session = sid && sessions.get(sid);
    sessions.delete(sid);
    if (session) await fatura.logout(session.token).catch(() => {});
    sendJSON(
      res,
      200,
      { ok: true },
      { "set-cookie": "sid=; HttpOnly; SameSite=Strict; Path=/; Max-Age=0" }
    );
  },

  "GET /api/me": async (req, res) => {
    const { token } = getSession(req);
    const user = await fatura.getUserData(token);
    sendJSON(res, 200, { user, testMode: process.env.FATURA_TEST === "1" });
  },

  "GET /api/invoices": async (req, res, url) => {
    const { token } = getSession(req);
    const startDate = url.searchParams.get("startDate");
    const endDate = url.searchParams.get("endDate");
    if (!isDate(startDate) || !isDate(endDate)) {
      throw new HttpError(400, "Tarih aralığı gerekli.");
    }
    const invoices = await fatura.getAllInvoicesByDateRange(token, {
      startDate,
      endDate
    });
    sendJSON(res, 200, { invoices: invoices || [] });
  },

  "POST /api/invoices": async (req, res) => {
    const { token } = getSession(req);
    const invoice = buildInvoice(await readBody(req));
    const draft = await fatura.createDraftInvoice(token, invoice);
    sendJSON(res, 200, {
      uuid: draft.uuid,
      date: draft.date,
      message: draft.data
    });
  },

  "POST /api/invoices/cancel": async (req, res) => {
    const { token } = getSession(req);
    const body = await readBody(req);
    const [invoice] = await findInvoicesByETTN(token, {
      ...body,
      ettns: [body.ettn]
    });
    if (invoice.onayDurumu !== "Onaylanmadı") {
      throw new HttpError(400, "Yalnızca onaylanmamış taslaklar silinebilir.");
    }
    const message = await fatura.cancelDraftInvoice(
      token,
      body.reason || "Hatalı düzenlendi",
      invoice
    );
    sendJSON(res, 200, { message });
  },

  "GET /api/invoice-html": async (req, res, url) => {
    const { token } = getSession(req);
    const html = await fatura.getInvoiceHTML(token, url.searchParams.get("ettn"), {
      signed: url.searchParams.get("signed") === "1"
    });
    res.writeHead(200, {
      "content-type": "text/html; charset=utf-8",
      "content-security-policy": "script-src 'none'"
    });
    res.end(html || "");
  },

  "GET /api/invoice-download": async (req, res, url) => {
    const { token } = getSession(req);
    const ettn = url.searchParams.get("ettn");
    const response = await fetch(
      fatura.getDownloadURL(token, ettn, {
        signed: url.searchParams.get("signed") === "1"
      })
    );
    if (!response.ok) throw new HttpError(502, "Fatura indirilemedi.");
    const buffer = await response.buffer();
    res.writeHead(200, {
      "content-type": "application/zip",
      "content-disposition": `attachment; filename="fatura-${ettn}.zip"`
    });
    res.end(buffer);
  },

  "GET /api/recipient": async (req, res, url) => {
    const { token } = getSession(req);
    const id = url.searchParams.get("id") || "";
    if (!/^\d{10,11}$/.test(id)) {
      throw new HttpError(400, "VKN 10, TCKN 11 haneli olmalıdır.");
    }
    const data = await fatura.getRecipientDataByTaxIDOrTRID(token, id);
    sendJSON(res, 200, { recipient: data || {} });
  },

  // GİB onayı 1. adım: kayıtlı cep telefonuna SMS şifresi gönderilir.
  "POST /api/sms/send": async (req, res) => {
    const { token } = getSession(req);
    const phone = await fatura.getPhoneNumber(token);
    if (!phone) {
      throw new HttpError(
        400,
        "GİB'de kayıtlı cep telefonu bulunamadı. e-Arşiv portalından telefon numaranızı tanımlayın."
      );
    }
    const oid = await fatura.sendSignSMSCode(token, phone);
    sendJSON(res, 200, {
      oid,
      phone: maskPhone(phone)
    });
  },

  // GİB onayı 2. adım: SMS şifresi ile seçili taslaklar imzalanır.
  "POST /api/sms/verify": async (req, res) => {
    const { token } = getSession(req);
    const body = await readBody(req);
    if (!/^\d{4,8}$/.test(body.code || "") || !body.oid) {
      throw new HttpError(400, "SMS şifresini kontrol edin.");
    }
    const invoices = await findInvoicesByETTN(token, body);
    const drafts = invoices.filter(
      invoice => invoice.onayDurumu === "Onaylanmadı"
    );
    if (!drafts.length) {
      throw new HttpError(400, "Seçilen faturalar zaten onaylanmış.");
    }
    const result = await fatura.verifySignSMSCode(
      token,
      body.code,
      body.oid,
      drafts
    );
    sendJSON(res, 200, { result, count: drafts.length });
  },

  // Bu cihazda WhatsApp ile doğrulanmış telefon var mı? (giriş ekranı için)
  "GET /api/whatsapp/remembered": async (req, res) => {
    const phone = getVerifiedPhone(req);
    sendJSON(res, 200, { phone: phone && maskPhone(phone) });
  },

  // Çıkışta doğrulama penceresi için: GİB'de kayıtlı telefon önerilir.
  "GET /api/whatsapp/status": async (req, res) => {
    const { token } = getSession(req);
    const verified = Boolean(getVerifiedPhone(req));
    const suggested = verified
      ? null
      : await fatura.getPhoneNumber(token).catch(() => null);
    sendJSON(res, 200, {
      verified,
      configured: whatsappConfigured(),
      suggestedPhone: suggested ? `0${normalizePhoneSafe(suggested)}` : ""
    });
  },

  "POST /api/whatsapp/send": async (req, res) => {
    const { sid } = getSession(req);
    const session = sessions.get(sid);
    if (!whatsappConfigured()) {
      throw new HttpError(503, "WhatsApp doğrulaması bu sunucuda yapılandırılmamış.");
    }
    const phone = normalizePhone((await readBody(req)).phone);
    const previous = session.whatsapp;
    if (previous && Date.now() - previous.sentAt < WHATSAPP_RESEND_WAIT) {
      throw new HttpError(429, "Yeni kod için lütfen bir dakika bekleyin.");
    }
    const code = String(crypto.randomInt(0, 1e6)).padStart(6, "0");
    await sendWhatsAppCode(phone, code);
    session.whatsapp = {
      phone,
      codeHash: hmac(`wa_code:${phone}:${code}`),
      sentAt: Date.now(),
      attempts: 0
    };
    sendJSON(res, 200, { phone: maskPhone(phone) });
  },

  "POST /api/whatsapp/verify": async (req, res) => {
    const { sid } = getSession(req);
    const session = sessions.get(sid);
    const pending = session.whatsapp;
    const { code } = await readBody(req);
    if (!pending || Date.now() - pending.sentAt > WHATSAPP_CODE_TTL) {
      delete session.whatsapp;
      throw new HttpError(400, "Kodun süresi doldu, yeni kod isteyin.");
    }
    if (++pending.attempts > WHATSAPP_MAX_ATTEMPTS) {
      delete session.whatsapp;
      throw new HttpError(429, "Çok fazla hatalı deneme, yeni kod isteyin.");
    }
    if (!safeEqual(hmac(`wa_code:${pending.phone}:${code}`), pending.codeHash)) {
      throw new HttpError(400, "WhatsApp kodu hatalı.");
    }
    delete session.whatsapp;
    const value = `${pending.phone}.${hmac(`wa_phone:${pending.phone}`)}`;
    sendJSON(
      res,
      200,
      { phone: maskPhone(pending.phone) },
      {
        "set-cookie": `wa_phone=${value}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${PHONE_COOKIE_TTL}${COOKIE_SECURE}`
      }
    );
  }
};

// GİB'den gelen numarayı öneri olarak gösterirken hata fırlatmadan biçimlendirir.
function normalizePhoneSafe(phone) {
  try {
    return normalizePhone(phone).slice(2);
  } catch (e) {
    return "";
  }
}

function serveStatic(res, file, type) {
  fs.readFile(path.join(__dirname, "public", file), (err, content) => {
    if (err) return sendJSON(res, 404, { error: "Bulunamadı" });
    res.writeHead(200, { "content-type": type });
    res.end(content);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);
  if (req.method === "GET" && url.pathname === "/") {
    return serveStatic(res, "index.html", "text/html; charset=utf-8");
  }
  const handler = routes[`${req.method} ${url.pathname}`];
  if (!handler) return sendJSON(res, 404, { error: "Bulunamadı" });
  try {
    await handler(req, res, url);
  } catch (error) {
    // GİB'in döndürdüğü hatalar (hatalı şifre, SMS kodu vb.) kullanıcı hatasıdır.
    const status = error.status || (error.gib ? 400 : 502);
    if (status >= 500) console.error(error);
    sendJSON(res, status, { error: error.message || "Beklenmeyen hata." });
  }
});

if (require.main === module) {
  server.listen(PORT, HOST, () => {
    console.log(
      `Fatura paneli: http://${HOST}:${PORT}` +
        (process.env.FATURA_TEST === "1" ? " (GİB TEST ortamı)" : "")
    );
  });
}

module.exports = { server, buildInvoice };
