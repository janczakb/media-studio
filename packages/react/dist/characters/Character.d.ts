import { type MotionValue } from 'motion/react';
import { type CharacterConfig, type CharacterState } from './model';
export type CharacterProps = {
    config: CharacterConfig;
    state?: CharacterState;
    size?: number;
    animate?: boolean;
    label?: string;
    audioLevel?: number | MotionValue<number>;
};
export declare const Character: import("react").NamedExoticComponent<CharacterProps>;
