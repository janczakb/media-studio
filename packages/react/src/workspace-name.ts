/** Count Unicode code points consistently with Laravel string validation. */
export function validWorkspaceName(name: string): boolean {
 const length = Array.from(name.trim()).length;
 return length >= 3 && length <= 15;
}
