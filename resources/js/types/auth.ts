export type User = {
    id: number;
    email: string;
    plan: 'free' | 'ad_free';
    timezone: string;
    is_admin: boolean;
    email_opted_out: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};
