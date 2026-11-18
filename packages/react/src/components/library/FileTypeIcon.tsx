import type {Entry} from '../../contracts';
import attachment from '../../../assets/file-types/color-attachment.svg';
import audio from '../../../assets/file-types/color-audio.svg';
import code from '../../../assets/file-types/color-code.svg';
import document from '../../../assets/file-types/color-document.svg';
import html from '../../../assets/file-types/color-html.svg';
import image from '../../../assets/file-types/color-image.svg';
import presentation from '../../../assets/file-types/color-presentation.svg';
import sheet from '../../../assets/file-types/color-sheet.svg';
import video from '../../../assets/file-types/color-video.svg';

const icons: Record<string, string> = {audio, code, document, html, image, presentation, spreadsheet: sheet, video, pdf: document, text: document};
const byExtension: Record<string, string> = {
  html, htm: html, js: code, jsx: code, ts: code, tsx: code, css: code, php: code, json: code, py: code,
  csv: sheet, xls: sheet, xlsx: sheet, ods: sheet, numbers: sheet, tsv: sheet,
  ppt: presentation, pptx: presentation, odp: presentation, key: presentation,
  doc: document, docx: document, odt: document, pdf: document, txt: document, md: document, rtf: document,
};

export function FileTypeIcon({entry, size}: {entry: Entry; size: number}) {
  const extension = entry.name.split('.').at(-1)?.toLowerCase() ?? '';
  return <img className="fms-file-type-icon" src={byExtension[extension] ?? icons[entry.mediaType ?? ''] ?? attachment} alt="" width={size} height={size}/>;
}
