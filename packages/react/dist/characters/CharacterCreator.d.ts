import { type CharacterConfig } from './model';
type Props = {
    value: CharacterConfig;
    onChange: (value: CharacterConfig) => void;
    onSave?: (value: CharacterConfig) => void;
    label?: string;
    embedded?: boolean;
};
export declare function CharacterCreator({ value, onChange, onSave, label, embedded }: Props): import("react/jsx-runtime").JSX.Element;
export {};
