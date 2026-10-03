// Basit e-Arşiv fatura paneli.
// Kullanım: npm start  ->  http://localhost:3000
// GİB test portalı için: FATURA_TEST=1 npm start
//
// WhatsApp telefon doğrulaması (cevaplama.com 2FA altyapısı) için ortam değişkenleri:
//   WA_OTP_URL                Sunucudaki wa-otp.php adresi (HTTPS)
//   WA_OTP_KEY                wa-otp.php ile paylaşılan gizli anahtar
//   COOKIE_SECRET             Telefon çerezini imzalamak için sabit gizli anahtar
//   COOKIE_SECURE=1           Çerezleri yalnızca HTTPS üzerinden gönder
//   BASE_PATH=/fat            Panel bir alt yolda yayınlanıyorsa (örn. https://sorgu.co/fat)
//   DATA_DIR                  Ayarlar, kayıtlı GİB bilgisi ve bilgi fişi kayıtları (varsayılan ./data)
//   DATA_SECRET               Kayıtlı GİB şifresini şifrelemek için anahtar (yoksa COOKIE_SECRET kullanılır)

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

// Panel bir alt yolda yayınlanıyorsa (BASE_PATH=/fat): istekler hem "/fat/api/..." hem de
// ters vekilin öneki kırptığı "/api/..." biçiminde gelebilir; ikisi de kabul edilir.
// Çerezler yalnızca bu alt yola yazılır.
const BASE_PATH = ("/" + (process.env.BASE_PATH || "")).replace(/\/+/g, "/").replace(/\/$/, "");
const COOKIE_PATH = BASE_PATH || "/";

// Sabit bir anahtar verilmezse sunucu her açıldığında kayıtlı telefon çerezleri geçersiz olur.
const COOKIE_SECRET =
  process.env.COOKIE_SECRET || crypto.randomBytes(32).toString("hex");

// Kalıcı veri: ayarlar (fiyatlar, işletme, kayıtlı GİB bilgisi) ve bilgi fişi kayıtları.
const DATA_DIR = process.env.DATA_DIR || path.join(__dirname, "data");
const SETTINGS_FILE = path.join(DATA_DIR, "ayarlar.json");
const RECEIPTS_FILE = path.join(DATA_DIR, "bilgi-fisleri.json");
const DATA_KEY = crypto
  .createHash("sha256")
  .update(process.env.DATA_SECRET || COOKIE_SECRET)
  .digest();
if (!process.env.DATA_SECRET && !process.env.COOKIE_SECRET) {
  console.warn(
    "Uyarı: DATA_SECRET/COOKIE_SECRET tanımlı değil; kayıtlı GİB bilgisi yeniden başlatmada okunamaz."
  );
}

const DEFAULT_SETTINGS = {
  isletme: { ad: "", adres: "" },
  fiyat: { kamyonet: 0, hususi: 0, kdv: 20 },
  gib: null // { username, passwordEnc }
};

const VEHICLE_TYPES = { kamyonet: "Kamyonet", hususi: "Hususi araç" };

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

function readJSON(file, fallback) {
  try {
    return JSON.parse(fs.readFileSync(file, "utf8"));
  } catch (e) {
    if (e.code !== "ENOENT") console.error(`${file} okunamadı:`, e.message);
    return fallback;
  }
}

function writeJSON(file, data) {
  fs.mkdirSync(DATA_DIR, { recursive: true, mode: 0o700 });
  const tmp = `${file}.${process.pid}.tmp`;
  fs.writeFileSync(tmp, JSON.stringify(data, null, 2), { mode: 0o600 });
  fs.renameSync(tmp, file);
}

function loadSettings() {
  const saved = readJSON(SETTINGS_FILE, {});
  return {
    isletme: { ...DEFAULT_SETTINGS.isletme, ...(saved.isletme || {}) },
    fiyat: { ...DEFAULT_SETTINGS.fiyat, ...(saved.fiyat || {}) },
    gib: saved.gib || null
  };
}

function saveSettings(settings) {
  writeJSON(SETTINGS_FILE, settings);
}

// AES-256-GCM: iv.tag.şifreli (base64)
function encrypt(text) {
  const iv = crypto.randomBytes(12);
  const cipher = crypto.createCipheriv("aes-256-gcm", DATA_KEY, iv);
  const enc = Buffer.concat([cipher.update(String(text), "utf8"), cipher.final()]);
  return [iv, cipher.getAuthTag(), enc].map(b => b.toString("base64")).join(".");
}

function decrypt(payload) {
  const [iv, tag, enc] = String(payload).split(".").map(part => Buffer.from(part, "base64"));
  const decipher = crypto.createDecipheriv("aes-256-gcm", DATA_KEY, iv);
  decipher.setAuthTag(tag);
  return Buffer.concat([decipher.update(enc), decipher.final()]).toString("utf8");
}

// Dışarıya verilen ayar görünümü: şifre asla gönderilmez.
function publicSettings(settings) {
  return {
    isletme: settings.isletme,
    fiyat: settings.fiyat,
    gibSaved: Boolean(settings.gib),
    gibUsername: settings.gib ? settings.gib.username : "",
    testMode: process.env.FATURA_TEST === "1"
  };
}

function money(value) {
  const number = Number(String(value).replace(",", "."));
  if (!Number.isFinite(number) || number < 0) throw new HttpError(400, "Tutarı kontrol edin.");
  return round(number);
}

function vehicleLabel(type) {
  const label = VEHICLE_TYPES[type];
  if (!label) throw new HttpError(400, "Araç cinsi kamyonet ya da hususi olmalıdır.");
  return label;
}

// KDV dahil tutardan tek kalemlik yıkama hizmeti satırı üretir; KDV dahil toplam
// yuvarlama farkı olmadan verilen tutara eşit çıkacak şekilde net fiyat seçilir.
function washInvoiceItem({ grossTotal, VATRate, vehicleType, plate }) {
  const rate = Number(VATRate);
  if (!Number.isFinite(rate) || rate < 0 || rate > 100) throw new HttpError(400, "KDV oranını kontrol edin.");
  const gross = money(grossTotal);
  if (gross <= 0) throw new HttpError(400, "Tutar sıfırdan büyük olmalıdır.");
  const base = round(gross / (1 + rate / 100));
  const net =
    [base, round(base - 0.01), round(base + 0.01)].find(
      candidate => candidate > 0 && round(candidate + round((candidate * rate) / 100)) === gross
    ) || base;
  const name = `Oto yıkama hizmeti - ${vehicleLabel(vehicleType)}${plate ? ` (${plate})` : ""}`;
  return { name, quantity: 1, unitType: "C62", unitPrice: net, VATRate: rate };
}

function normalizePlate(plate) {
  const value = String(plate || "").toUpperCase().replace(/[^A-Z0-9 ]/g, "").trim().slice(0, 12);
  return value;
}

async function openSession(username, password) {
  const token = await fatura.getToken(
    encodeURIComponent(String(username).trim()),
    encodeURIComponent(password)
  );
  const sid = crypto.randomBytes(32).toString("hex");
  sessions.set(sid, { token, lastUsed: Date.now() });
  const user = await fatura.getUserData(token).catch(() => null);
  return { sid, token, user };
}

function sessionCookie(sid) {
  return `sid=${sid}; HttpOnly; SameSite=Strict; Path=${COOKIE_PATH}; Max-Age=${SESSION_TTL / 1000}${COOKIE_SECURE}`;
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
  // { username, password, remember? }  ya da  { saved: true } (kayıtlı GİB bilgisiyle giriş)
  "POST /api/login": async (req, res) => {
    const body = await readBody(req);
    let { username, password } = body;
    if (body.saved) {
      const { gib } = loadSettings();
      if (!gib) throw new HttpError(400, "Kayıtlı GİB bilgisi yok.");
      username = gib.username;
      try {
        password = decrypt(gib.passwordEnc);
      } catch (e) {
        throw new HttpError(500, "Kayıtlı GİB şifresi okunamadı; bilgileri yeniden kaydedin.");
      }
    }
    if (!username || !password) {
      throw new HttpError(400, "Kullanıcı kodu ve şifre gerekli.");
    }
    const { sid, user } = await openSession(username, password);
    if (body.remember && !body.saved) {
      const settings = loadSettings();
      settings.gib = { username: String(username).trim(), passwordEnc: encrypt(password) };
      saveSettings(settings);
    }
    sendJSON(
      res,
      200,
      { user, testMode: process.env.FATURA_TEST === "1" },
      { "set-cookie": sessionCookie(sid) }
    );
  },

  // Giriş ekranı için: bu sunucuda kayıtlı GİB bilgisi var mı?
  "GET /api/login/saved": async (req, res) => {
    const { gib } = loadSettings();
    sendJSON(res, 200, { saved: Boolean(gib), username: gib ? gib.username : "" });
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
      { "set-cookie": `sid=; HttpOnly; SameSite=Strict; Path=${COOKIE_PATH}; Max-Age=0` }
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
  // Ayarlar: işletme bilgisi, araç cinsine göre fiyatlar, KDV, kayıtlı GİB bilgisi durumu
  "GET /api/settings": async (req, res) => {
    getSession(req);
    sendJSON(res, 200, publicSettings(loadSettings()));
  },

  "PUT /api/settings": async (req, res) => {
    getSession(req);
    const body = await readBody(req);
    const settings = loadSettings();
    if (body.isletme) {
      settings.isletme = {
        ad: String(body.isletme.ad || "").trim().slice(0, 120),
        adres: String(body.isletme.adres || "").trim().slice(0, 300)
      };
    }
    if (body.fiyat) {
      const kdv = Number(body.fiyat.kdv);
      if (!Number.isFinite(kdv) || kdv < 0 || kdv > 100) throw new HttpError(400, "KDV oranını kontrol edin.");
      settings.fiyat = {
        kamyonet: money(body.fiyat.kamyonet),
        hususi: money(body.fiyat.hususi),
        kdv
      };
    }
    saveSettings(settings);
    sendJSON(res, 200, publicSettings(settings));
  },

  "DELETE /api/settings/gib": async (req, res) => {
    getSession(req);
    const settings = loadSettings();
    settings.gib = null;
    saveSettings(settings);
    sendJSON(res, 200, publicSettings(settings));
  },

  // Bilgi fişi: mali değeri olmayan, sıra numaralı yerel kayıt.
  "POST /api/arac/fis": async (req, res) => {
    getSession(req);
    const body = await readBody(req);
    const settings = loadSettings();
    const receipts = readJSON(RECEIPTS_FILE, []);
    const now = istanbulNow();
    const receipt = {
      no: receipts.length ? receipts[receipts.length - 1].no + 1 : 1,
      tarih: now.date,
      saat: now.time,
      aracCinsi: vehicleLabel(body.vehicleType),
      plaka: normalizePlate(body.plate),
      tutar: money(body.amount),
      kdvOrani: settings.fiyat.kdv,
      isletme: settings.isletme,
      not: "Bilgi fişidir, mali değeri yoktur."
    };
    if (receipt.tutar <= 0) throw new HttpError(400, "Tutar sıfırdan büyük olmalıdır.");
    receipts.push(receipt);
    writeJSON(RECEIPTS_FILE, receipts.slice(-5000));
    sendJSON(res, 200, { receipt });
  },

  "GET /api/arac/fisler": async (req, res) => {
    getSession(req);
    const receipts = readJSON(RECEIPTS_FILE, []);
    sendJSON(res, 200, { receipts: receipts.slice(-100).reverse() });
  },

  // Araç yıkama için tek kalemlik e-Arşiv taslağı keser, ardından günün
  // onaylı/onaysız faturalarını döndürür. Alıcı verilmezse nihai tüketici.
  "POST /api/arac/fatura": async (req, res) => {
    const { token } = getSession(req);
    const body = await readBody(req);
    const settings = loadSettings();
    const plate = normalizePlate(body.plate);
    const item = washInvoiceItem({
      grossTotal: body.amount,
      VATRate: settings.fiyat.kdv,
      vehicleType: body.vehicleType,
      plate
    });
    const recipient = body.recipient || {};
    const hasRecipient = /^\d{10,11}$/.test(recipient.taxIDOrTRID || "");
    const invoice = buildInvoice({
      taxIDOrTRID: hasRecipient ? recipient.taxIDOrTRID : "11111111111",
      title: hasRecipient ? recipient.title || "" : "",
      name: hasRecipient ? recipient.name || "" : "Nihai",
      surname: hasRecipient ? recipient.surname || "" : "Tüketici",
      taxOffice: recipient.taxOffice || "",
      fullAddress: recipient.fullAddress || "",
      district: recipient.district || "",
      city: recipient.city || "",
      email: recipient.email || "",
      items: [item]
    });
    const draft = await fatura.createDraftInvoice(token, invoice);
    const today = istanbulNow().date;
    const invoices =
      (await fatura
        .getAllInvoicesByDateRange(token, { startDate: today, endDate: today })
        .catch(() => null)) || [];
    sendJSON(res, 200, {
      uuid: draft.uuid,
      message: draft.data,
      invoice,
      approved: invoices.filter(inv => inv.onayDurumu === "Onaylandı"),
      unapproved: invoices.filter(inv => inv.onayDurumu !== "Onaylandı")
    });
  },

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
        "set-cookie": `wa_phone=${value}; HttpOnly; SameSite=Strict; Path=${COOKIE_PATH}; Max-Age=${PHONE_COOKIE_TTL}${COOKIE_SECURE}`
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

// Ek sayfalar (alt yol altında da aynı adlarla: /fat/arac)
const PAGES = { "/arac": "arac.html", "/arac.html": "arac.html" };

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, `http://${req.headers.host || "localhost"}`);
  let pathname = url.pathname;
  if (BASE_PATH && (pathname === BASE_PATH || pathname.startsWith(BASE_PATH + "/"))) {
    pathname = pathname.slice(BASE_PATH.length) || "/";
  }
  if (req.method === "GET" && pathname === "/") {
    // Göreli API adreslerinin doğru çözülmesi için alt yol her zaman "/" ile bitmeli.
    if (BASE_PATH && !url.pathname.endsWith("/")) {
      res.writeHead(302, { location: `${url.pathname}/${url.search}` });
      return res.end();
    }
    return serveStatic(res, "index.html", "text/html; charset=utf-8");
  }
  if (req.method === "GET" && PAGES[pathname]) {
    return serveStatic(res, PAGES[pathname], "text/html; charset=utf-8");
  }
  const handler = routes[`${req.method} ${pathname}`];
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
      `Fatura paneli: http://${HOST}:${PORT}${BASE_PATH}/` +
        (process.env.FATURA_TEST === "1" ? " (GİB TEST ortamı)" : "")
    );
  });
}

module.exports = { server, buildInvoice, washInvoiceItem, sessions };
