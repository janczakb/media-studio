---
title: "Animated characters"
---

The character creator previews silhouettes, colors, eyes, eyebrows, mouths and accessories. The local `/characters` example saves appearance on the current device; it does not save a team profile to the server.

```tsx
import {Character, CharacterCreator, defaultCharacter} from '@useflex/media-studio/characters';
import '@useflex/media-studio/characters.css';

<Character config={appearance} state="thinking" size={32} />
<CharacterCreator value={appearance} onChange={setAppearance} onSave={saveAppearance} />
```

`appearance` is a versioned plain object. Use `normalizeCharacter()` when loading untrusted or older saved data. It validates enumerations, colors and numerical limits.

## Motion

- `personality`: `calm`, `curious`, `energetic` adjusts the main state tempo.
- `intensity`: 0–1.5, defaults to 1. Zero disables animated behavior.
- `trackPointer`: enables bounded gaze tracking near the visible character.
- Component `animate={false}` disables animation for static thumbnails.
- The operating system's reduced-motion preference disables loops and cursor tracking.

States: `idle`, `listening`, `thinking`, `speaking`, `happy`, `greeting`, `success`, `surprised`, `uncertain`, `error`. Reaction body animations play once. The host controls when to return to idle; the creator demonstrates this automatically. Its “Try a task” button demonstrates thinking → success → idle.

## Connect playback audio

An application can supply its existing Web Audio analyser. The helper does not open a microphone, create an audio context or change the application's audio routing.

```tsx
const driver = createCharacterAudioDriver(analyser, {gain: 4});

<Character config={appearance} state="speaking" audioLevel={driver.level} />

// When the playback view is disposed:
driver.destroy();
```

The driver reads a bounded waveform buffer at up to 30 updates/second and passes a MotionValue directly to the mouth, avoiding React updates for audio samples. It pauses while the page is hidden. The caller owns and disconnects the analyser/source/context. Without `audioLevel`, speaking uses the demonstration animation. This is audio-amplitude animation, not phoneme recognition or exact lip synchronization.
