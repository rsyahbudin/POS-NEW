import net from "node:net";

//#region resources/js/lib/format.ts
function rupiah(amount) {
	const n = Math.trunc(amount ?? 0);
	return `${n < 0 ? "-" : ""}Rp${Math.abs(n).toLocaleString("id-ID")}`;
}

//#endregion
//#region resources/js/lib/escpos.ts
const columns = (w) => w === 58 ? 32 : 48;
function pad2(left, right, width) {
	const space = Math.max(1, width - left.length - right.length);
	return left.slice(0, Math.max(0, width - right.length - 1)) + " ".repeat(space) + right;
}
/** Plain ESC/POS payload for a customer receipt. */
function receiptEscPos(r, paper) {
	const w = columns(paper);
	const line = "-".repeat(w);
	const out = [
		"\x1B@",
		"\x1Ba",
		"\x1BE",
		`${r.outlet.name ?? ""}\n`,
		"\x1BE\0"
	];
	if (r.outlet.address) out.push(`${r.outlet.address}\n`);
	if (r.outlet.npwp) out.push(`NPWP ${r.outlet.npwp}\n`);
	if (r.header) out.push(`${r.header}\n`);
	if (r.title) out.push("\x1BE", `*** ${r.title} ***\n`, "\x1BE\0");
	if (r.copy) out.push("\x1BE", "*** COPY ***\n", "\x1BE\0");
	out.push("\x1Ba\0", `${line}\n`);
	out.push(pad2("No", r.order_number, w) + "\n");
	out.push(pad2("Tanggal", r.date, w) + "\n");
	if (r.cashier) out.push(pad2("Kasir", r.cashier, w) + "\n");
	if (r.table) out.push(pad2("Meja", r.table, w) + "\n");
	if (r.call_number) out.push(pad2("Panggil", r.call_number, w) + "\n");
	if (r.label) out.push(pad2("Tagihan", r.label, w) + "\n");
	out.push(`${line}\n`);
	for (const i of r.items) {
		out.push(`${i.name}\n`);
		for (const m of i.modifiers) out.push(`  + ${m}\n`);
		out.push(pad2(`  ${i.qty} x ${rupiah(i.unit_price)}`, rupiah(i.line_total), w) + "\n");
	}
	out.push(`${line}\n`, pad2("Subtotal", rupiah(r.subtotal), w) + "\n");
	if (r.discount_total > 0) out.push(pad2("Diskon", `-${rupiah(r.discount_total)}`, w) + "\n");
	for (const c of r.charges) out.push(pad2(c.is_inclusive ? `${c.name} (incl.)` : c.name, rupiah(c.amount), w) + "\n");
	if (r.rounding !== 0) out.push(pad2("Pembulatan", rupiah(r.rounding), w) + "\n");
	out.push("\x1BE", pad2("TOTAL", rupiah(r.grand_total), w) + "\n", "\x1BE\0");
	for (const p of r.payments) out.push(pad2(p.method, rupiah(p.tendered), w) + "\n");
	if (r.change > 0) out.push(pad2("Kembali", rupiah(r.change), w) + "\n");
	if (r.footer) out.push("\x1Ba", `\n${r.footer}\n`);
	if (r.show_link) out.push("\x1Ba", `\n${r.public_url}\n`);
	out.push("\n\n\n", "V\0");
	return out;
}
function kitchenEscPos(t, paper) {
	const w = columns(paper);
	const out = [
		"\x1B@",
		"\x1Ba",
		"\x1B!0",
		`${t.label}\n`,
		"\x1B!\0",
		`${t.number}  ${t.time}\n`,
		"\x1Ba\0",
		"-".repeat(w) + "\n"
	];
	for (const i of t.items) {
		out.push("\x1B!", `${i.qty} x ${i.name}\n`, "\x1B!\0");
		for (const m of i.modifiers) out.push(`   + ${m}\n`);
		if (i.note) out.push(`   * ${i.note}\n`);
	}
	out.push("\n\n\n", "V\0");
	return out;
}

//#endregion
//#region bridge/print-bridge.mjs
const arg = (name) => {
	const i = process.argv.indexOf(`--${name}`);
	return i > -1 ? process.argv[i + 1] : void 0;
};
const base = (arg("url") ?? process.env.PRINT_BRIDGE_URL ?? "").replace(/\/+$/, "");
const token = arg("token") ?? process.env.PRINT_BRIDGE_TOKEN ?? "";
const every = Number(arg("every") ?? 3) * 1e3;
if (!base || !token) {
	console.error("Pakai: node print-bridge.mjs --url https://alamat-pos --token pb_xxxxx");
	process.exit(1);
}
const log = (...a) => console.log((/* @__PURE__ */ new Date()).toLocaleTimeString("id-ID"), ...a);
async function call(method, path, body) {
	const res = await fetch(`${base}/bridge/print/${path}`, {
		method,
		headers: {
			Authorization: `Bearer ${token}`,
			Accept: "application/json",
			"Content-Type": "application/json"
		},
		body: body ? JSON.stringify(body) : void 0,
		signal: AbortSignal.timeout(15e3)
	});
	if (res.status === 401) throw new Error("Token ditolak server. Buat token baru di Pengaturan > Printer.");
	if (!res.ok) throw new Error(`Server membalas ${res.status}`);
	return res.json();
}
/** ESC/POS text is single-byte; anything outside it would print as garbage, so show a question mark instead. */
const bytes = (parts) => Buffer.from(parts.join("").replace(/[^\x00-\xff]/g, "?"), "latin1");
function send(host, port, data) {
	return new Promise((resolve, reject) => {
		const socket = net.connect({
			host,
			port
		});
		socket.setTimeout(8e3, () => socket.destroy(/* @__PURE__ */ new Error("Printer tidak merespons (timeout).")));
		socket.once("error", (e) => reject(/* @__PURE__ */ new Error(`Tidak bisa terhubung ke ${host}:${port} (${e.code ?? e.message})`)));
		socket.once("connect", () => socket.end(data, () => resolve()));
		socket.once("close", () => resolve());
	});
}
async function print(job) {
	const { printer, payload } = job;
	const render = job.kind === "ticket" ? kitchenEscPos : receiptEscPos;
	const copies = Math.max(1, Number(payload.copies ?? 1));
	const data = bytes(Array.from({ length: copies }, () => render(payload, printer.paper_width)).flat());
	let last;
	for (let attempt = 1; attempt <= 3; attempt++) try {
		await send(printer.host, printer.port, data);
		return;
	} catch (e) {
		last = e;
		await new Promise((r) => setTimeout(r, 1500));
	}
	throw last;
}
let busy = false;
async function tick() {
	if (busy) return;
	busy = true;
	try {
		const { jobs } = await call("GET", "jobs");
		for (const job of jobs) try {
			await print(job);
			await call("POST", `jobs/${job.id}/done`, { ok: true });
			log(`OK   ${job.printer.name}: ${job.kind} ${job.reference ?? ""}`);
		} catch (e) {
			await call("POST", `jobs/${job.id}/done`, {
				ok: false,
				error: String(e.message).slice(0, 190)
			});
			log(`GAGAL ${job.printer.name}: ${e.message}`);
		}
	} catch (e) {
		log("Server tidak terjangkau:", e.message);
		if (String(e.message).startsWith("Token ditolak")) process.exit(2);
	} finally {
		busy = false;
	}
}
log(`Jembatan cetak aktif. Server: ${base}, cek tiap ${every / 1e3} detik.`);
setInterval(tick, every);
tick();

//#endregion
export {  };