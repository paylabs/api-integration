import "dotenv/config";
import express, { json } from "express";
import { createHash, createVerify } from "crypto";
import path from "path";
import { fileURLToPath } from "url";

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

const app = express();
app.use(json());
app.use(express.static(path.join(__dirname, "views")));

// SSE Clients
let clients = [];

app.get("/events", (req, res) => {
  res.setHeader("Content-Type", "text/event-stream");
  res.setHeader("Cache-Control", "no-cache");
  res.setHeader("Connection", "keep-alive");

  clients.push(res);

  req.on("close", () => {
    clients = clients.filter((client) => client !== res);
  });
});

function broadcast(data) {
  clients.forEach((client) =>
    client.write(`data: ${JSON.stringify(data)}\n\n`),
  );
}

app.get("/", (req, res) => {
  res.sendFile(path.join(__dirname, "views", "index.html"));
});

// Outbound Logging Endpoint
app.post("/log", (req, res) => {
  console.log("Outbound Log received:", req.body.type || "transaction");
  broadcast({
    type: "outbound",
    ...req.body,
  });
  res.sendStatus(200);
});

app.post("/callback", (req, res) => {
  const signature = req.headers["x-signature"];
  const timestamp = req.headers["x-timestamp"];
  const publicKey = process.env.PAYLABS_PUBLIC_KEY;

  console.log("Incoming Callback Headers:", req.headers);

  const dataToSign = JSON.stringify(req.body);

  const shaJson = createHash("sha256").update(dataToSign).digest("hex");

  const stringToVerify = `POST:/callback:${shaJson}:${timestamp}`;
  console.log("String to Verify:", stringToVerify);

  const verifier = createVerify("RSA-SHA256");
  verifier.update(stringToVerify);
  verifier.end();

  let valid = false;
  try {
    valid = verifier.verify(publicKey, signature, "base64");
  } catch (error) {
    console.error("Signature verification error:", error);
  }

  if (!valid) {
    console.log("Invalid Signature");
    broadcast({
      id: req.body.requestId || require("crypto").randomUUID(),
      type: "inbound",
      headers: req.headers,
      body: req.body,
      endpoint: "/callback",
      verificationStatus: "Invalid",
    });
    return res.status(400).send("Invalid Signature");
  }

  console.log("Signature is valid");

  const { requestId, merchantId, status } = req.body;

  const responseData =
    status !== "02"
      ? {
          requestId,
          errCode: "1",
          errCodeDes: "Payment not completed",
          merchantId,
        }
      : { requestId, errCode: "0", errCodeDes: "Success", merchantId };

  broadcast({
    id: requestId || require("crypto").randomUUID(),
    type: "inbound",
    headers: req.headers,
    body: req.body,
    endpoint: "/callback",
    verificationStatus: "Valid",
    responseBody: responseData,
  });

  res.status(200).json(responseData);
});

app.post("/api/v1.0/transfer-va/create-va", (req, res) => {
  const signature = req.headers["x-signature"];
  const timestamp = req.headers["x-timestamp"];
  const publicKey = process.env.PAYLABS_PUBLIC_KEY;

  console.log("Incoming SNAP Create VA Headers:", req.headers);

  const dataToSign = JSON.stringify(req.body);
  const shaJson = createHash("sha256")
    .update(dataToSign)
    .digest("hex")
    .toLowerCase();

  const stringToVerify = `POST:/transfer-va/create-va:${shaJson}:${timestamp}`;
  console.log("SNAP Create VA String to Verify:", stringToVerify);

  const verifier = createVerify("RSA-SHA256");
  verifier.update(stringToVerify);
  verifier.end();

  let valid = false;
  try {
    valid = verifier.verify(publicKey, signature, "base64");
  } catch (error) {
    console.error("Signature verification error:", error);
  }

  const responseData = {
    responseCode: valid ? "2002700" : "4012701",
    responseMessage: valid ? "Success" : "Invalid Signature",
  };

  broadcast({
    id: req.body.paymentRequestId || require("crypto").randomUUID(),
    type: "inbound",
    headers: req.headers,
    body: req.body,
    endpoint: "/api/v1.0/transfer-va/create-va",
    verificationStatus: valid ? "Valid" : "Invalid",
    responseBody: responseData,
  });

  if (!valid) {
    return res.status(401).json(responseData);
  }

  res.status(200).json(responseData);
});

app.post("/transfer-va/payment", (req, res) => {
  const signature = req.headers["x-signature"];
  const timestamp = req.headers["x-timestamp"];
  const partnerId = req.headers["x-partner-id"];
  const externalId = req.headers["x-external-id"];
  const ipAddress = req.headers["x-ip-address"];
  const publicKey = process.env.PAYLABS_PUBLIC_KEY;

  console.log("Incoming SNAP Callback Headers:", req.headers);

  // Minify body (remove whitespace) for signature verification
  const dataToSign = JSON.stringify(req.body);
  const shaJson = createHash("sha256")
    .update(dataToSign)
    .digest("hex")
    .toLowerCase();

  // Pattern: POST:/transfer-va/payment:{bodyHash}:{timestamp}
  // Note: SNAP might use the full path or stripped path.
  // Based on current Paylabs impl, it's likely /transfer-va/payment
  const stringToVerify = `POST:/transfer-va/payment:${shaJson}:${timestamp}`;
  console.log("SNAP String to Verify:", stringToVerify);

  const verifier = createVerify("RSA-SHA256");
  verifier.update(stringToVerify);
  verifier.end();

  let valid = false;
  try {
    valid = verifier.verify(publicKey, signature, "base64");
  } catch (error) {
    console.error("Signature verification error:", error);
  }

  if (!valid) {
    console.log("Invalid SNAP Signature");
    broadcast({
      id: req.body.paymentRequestId || require("crypto").randomUUID(),
      type: "inbound",
      headers: req.headers,
      body: req.body,
      endpoint: "/transfer-va/payment",
      verificationStatus: "Invalid",
    });
    return res.status(401).json({
      responseCode: "4010000",
      responseMessage: "Unauthorized",
    });
  }

  console.log("SNAP Signature is valid");

  // Allowed fields for SNAP VA callback response
  const allowedFields = [
    "paidBills",
    "virtualAccountNo",
    "paymentRequestId",
    "partnerServiceId",
    "virtualAccountPhone",
    "virtualAccountName",
    "journalNum",
    "flagAdvise",
    "trxId",
    "paymentFlagReason",
    "virtualAccountEmail",
    "billDetails",
    "totalAmount",
    "customerNo",
    "paymentType",
    "paidAmount",
    "referenceNo",
    "trxDateTime",
    "freeTexts",
    "paymentFlagStatus",
  ];

  const filteredBody = {};
  for (const key of allowedFields) {
    if (Object.prototype.hasOwnProperty.call(req.body, key)) {
      filteredBody[key] = req.body[key];
    }
  }
  filteredBody.paymentFlagStatus = "00";

  const responseData = {
    responseCode: "2002500",
    responseMessage: "Success",
    virtualAccountData: filteredBody,
  };

  broadcast({
    id: req.body.paymentRequestId || require("crypto").randomUUID(),
    type: "inbound",
    headers: req.headers,
    body: req.body,
    endpoint: "/transfer-va/payment",
    verificationStatus: "Valid",
    responseBody: responseData,
  });

  res.status(200).json(responseData);
});

const PORT = process.env.PORT || 3000;
app.listen(PORT, () => {
  console.log(`Callback server listening on port ${PORT}`);
  console.log(`Open http://localhost:${PORT} to visualize callbacks`);
});
