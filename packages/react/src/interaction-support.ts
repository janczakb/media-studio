// React Aria's Shadow DOM support is opt-in. Enable it for this SDK's shared
// React Stately instance before mounting any HeroUI control. This is the only
// private dependency boundary; keep it covered by browser interaction tests.
import {enableShadowDOM} from 'react-stately/private/flags/flags';

export function enableStudioInteractions(): void {
  enableShadowDOM();
}
