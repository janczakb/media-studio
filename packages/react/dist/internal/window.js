// src/window.ts
function appendPage(pages, incoming, direction, maximum = 6) {
  if (!Number.isInteger(maximum) || maximum < 2) throw new Error("A listing window requires at least two pages.");
  const next = direction === "next" ? [...pages, incoming] : [incoming, ...pages];
  return direction === "next" ? next.slice(-maximum) : next.slice(0, maximum);
}
export {
  appendPage
};
