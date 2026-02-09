import { createHash, createSign } from "crypto";

function removeNulls(obj) {
  if (Array.isArray(obj)) return obj.map(removeNulls);

  if (obj && typeof obj === "object") {
    return Object.fromEntries(
      Object.entries(obj)
        .filter(([_, v]) => v !== null)
        .map(([k, v]) => [k, removeNulls(v)]),
    );
  }

  return obj;
}

function minifyJSON(body) {
  return JSON.stringify(removeNulls(body));
}

function sha256Hex(data) {
  return createHash("sha256").update(data).digest("hex").toLowerCase();
}

function generateTimestamp() {
  const now = new Date();
  const wibTime = new Date(now.getTime() + 7 * 60 * 60 * 1000);
  return wibTime.toISOString().replace("Z", "+07:00");
}

export function generateRequestId() {
  const now = new Date();
  const wibTime = new Date(now.getTime() + 7 * 60 * 60 * 1000);
  const dateStr = wibTime
    .toISOString()
    .replace(/[-:T.Z]/g, "")
    .slice(0, 14);
  const randomStr = Math.floor(Math.random() * 1000000)
    .toString()
    .padStart(6, "0");
  return `${dateStr}${randomStr}`;
}

export function generateSignature({ method, endpoint, body }) {
  const timestamp = generateTimestamp();
  const privateKey = process.env.MERCHANT_PRIVATE_KEY;

  const bodyHash = sha256Hex(minifyJSON(body));
  console.log(minifyJSON(body));

  const stringToSign = `${method}:${endpoint}:${bodyHash}:${timestamp}`;
  console.log("String to Sign:", stringToSign);

  const signer = createSign("RSA-SHA256");
  signer.update(stringToSign);
  signer.end();

  const signature = signer.sign(privateKey, "base64");
  console.log("Generated Signature:", signature);

  return { signature, timestamp };
}

async function logToLocalServer(data) {
  const logPort = process.env.PORT || 3000;
  try {
    await fetch(`http://localhost:${logPort}/log`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(data),
    });
  } catch (e) {
    // Ignore logging errors
  }
}

/**
 * Generic function to create a transaction
 * @param {string} endpoint - The API endpoint (e.g., /payment/v2/transaction/create)
 * @param {object} body - The request body
 */
export async function createTransaction(endpoint, body) {
  const merchantId = process.env.MERCHANT_ID;

  try {
    const { signature, timestamp } = generateSignature({
      method: "POST",
      endpoint,
      body,
    });

    const response = await fetch(process.env.PAYLABS_BASE_URL + endpoint, {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
        "X-PARTNER-ID": merchantId,
        "X-TIMESTAMP": timestamp,
        "X-SIGNATURE": signature,
        "X-REQUEST-ID": body.requestId, // Use requestId from body
      },
      body: JSON.stringify(body),
    });

    const data = await response.json();
    console.log("Response:", JSON.stringify(data, null, 2));

    await logToLocalServer({
      type: "outbound",
      method: "POST",
      endpoint,
      requestHeaders: {
        "X-PARTNER-ID": merchantId,
        "X-TIMESTAMP": timestamp,
        "X-SIGNATURE": signature,
        "X-REQUEST-ID": body.requestId,
      },
      requestBody: body,
      responseBody: data,
    });

    return data;
  } catch (error) {
    console.error("Error:", error.message);
  }
}

export async function getPublicIp() {
  try {
    const response = await fetch("https://api.ipify.org?format=json");
    const data = await response.json();
    return data.ip;
  } catch (error) {
    console.warn(
      "Failed to fetch public IP, defaulting to 127.0.0.1:",
      error.message,
    );
    return "127.0.0.1";
  }
}

export async function createTransactionSnap(endpoint, body) {
  const merchantId = process.env.MERCHANT_ID;
  const externalId = body.externalId || body.requestId || generateRequestId(); // SNAP uses externalId

  try {
    const ipAddress = await getPublicIp();
    console.log("Public IP:", ipAddress);

    // SNAP signature uses a modified endpoint (e.g. /api/v1.0/transfer-va/create-va -> /transfer-va/create-va)
    const signatureEndpoint = endpoint.replace(/^\/api\/v\d+\.\d+/, "");

    const { signature, timestamp } = generateSignature({
      method: "POST",
      endpoint: signatureEndpoint,
      body,
    });

    // SNAP Headers
    const headers = {
      "Content-Type": "application/json;charset=utf-8",
      "X-PARTNER-ID": merchantId,
      "X-TIMESTAMP": timestamp,
      "X-SIGNATURE": signature,
      "X-EXTERNAL-ID": externalId,
      "X-IP-ADDRESS": ipAddress,
    };

    // If using original Paylabs signature logic (RSA-SHA256), the structure is likely specific.
    // However, typical SNAP B2B also uses Authorization: Bearer <AccessToken> for symmetric calls,
    // but for "Transaction" it often uses Asymmetric if direct, or Symmetric if via Token.
    // Assuming this `createTransactionSnap` is for the Asymmetric Direct OR similar to existing but with SNAP headers.
    // Based on existing Paylabs docs for SNAP, it uses X-SIGNATURE (Asym) for "Binding" or "Direct" often?
    // Let's assume re-using generateSignature (RSA) is correct for the user meant context,
    // but headers are SNAP compliant.

    const response = await fetch(process.env.PAYLABS_BASE_URL + endpoint, {
      method: "POST",
      headers: headers,
      body: JSON.stringify(body),
    });

    const data = await response.json();
    console.log("Response:", JSON.stringify(data, null, 2));

    await logToLocalServer({
      type: "outbound",
      method: "POST",
      endpoint,
      requestHeaders: headers,
      requestBody: body,
      responseBody: data,
    });

    return data;
  } catch (error) {
    console.error("Error:", error.message);
  }
}
