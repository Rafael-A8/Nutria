export type Profile = {
    id: number;
    user_id: number;
    gender: string | null;
    birth_date: string | null;
    height_cm: number | null;
    goal: string | null;
    activity_level: string | null;
    created_at: string;
    updated_at: string;
};

export type ChatMessage = {
    id: number;
    role: 'user' | 'assistant';
    content: string;
    audio_path: string | null;
    created_at: string;
};

export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    profile?: Profile | null;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
