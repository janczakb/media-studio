import {toDataURL} from 'qrcode';

/** The URL is encoded locally; no QR service receives the share token. */
export function encodeShareQr(url: string): Promise<string> {
  return toDataURL(url, {errorCorrectionLevel: 'M', margin: 4, width: 320});
}
