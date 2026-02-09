import "dotenv/config";
import {
  generateRequestId,
  createTransaction,
  createTransactionSnap,
} from "./paylabsSignature.js";

// Example Usage: Generate QRIS
export async function createQRIS() {
  const endpoint = process.env.QRIS_CREATE_ENDPOINT; // e.g. /payment/v2/qris/create
  const requestId = generateRequestId();

  const body = {
    merchantId: process.env.MERCHANT_ID,
    merchantTradeNo: "QRIS-" + requestId,
    requestId: requestId,
    paymentType: "QRIS", // Change to "General" or other types as needed
    amount: "10000.00",
    productName: "QRIS Payment",
    expire: 3600,
    feeType: "OUR",
    payer: "John Doe",
    notifyUrl:
      "https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback",
  };

  console.log("Creating QRIS Transaction...");
  await createTransaction(endpoint, body);
}

// Function to generate General Transaction (Example)
export async function createGeneralTransaction() {
  const endpoint = "/payment/v2.3/qris/create"; // Adjust endpoint for General Transaction if different
  const requestId = generateRequestId();

  const body = {
    merchantId: process.env.MERCHANT_ID,
    merchantTradeNo: "QRIS-" + requestId,
    requestId: requestId,
    paymentType: "QRIS", // Change to "General" or other types as needed
    amount: "10000.00",
    productName: "QRIS Payment",
    expire: 3600,
    feeType: "OUR",
    payer: "John Doe",
    notifyUrl:
      "https://ungirlishly-unmoralistic-antony.ngrok-free.dev/callback",
  };

  console.log("Creating General Transaction...");
  await createTransaction(endpoint, body);
}

// Function to generate SNAP Transaction (Example)
export async function createSnapTransaction() {
  const endpoint = "/api/v1.0/transfer-va/create-va"; // Adjust endpoint for SNAP if different
  const requestId = generateRequestId();
  const merchantId = process.env.MERCHANT_ID;

  const body = {
    partnerServiceId: `00${merchantId}`,
    customerNo: "00000000000000000000",
    virtualAccountNo: "000105796289500005539",
    virtualAccountName: "SUCCESS John - shoes**",
    virtualAccountPhone: "+6281234567890",
    trxId: `PYL${requestId}`,
    totalAmount: {
      value: "10000.00",
      currency: "IDR",
    },
    billDetails: [
      {
        billCode: "1",
        billName: "Produk John",
        billAmount: {
          value: "10000.00",
          currency: "IDR",
        },
      },
    ],
    expiredDate: "2026-12-25T15:52:34+07:00",
    virtualAccountTrxType: "1",
    additionalInfo: {
      paymentType: "MuamalatVA",
    },
  };

  console.log("Creating SNAP Transaction...");
  await createTransactionSnap(endpoint, body); // Pass requestId as externalId
}

// Uncomment the one you want to run
// createQRIS();
// createGeneralTransaction();
createSnapTransaction();
