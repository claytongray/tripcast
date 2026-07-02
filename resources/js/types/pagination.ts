/** One entry in Laravel's paginator `links` array. */
export type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

/** A Laravel length-aware paginator serialized for Inertia. */
export type Paginated<T> = {
    data: T[];
    links: PaginationLink[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    from: number | null;
    to: number | null;
    prev_page_url: string | null;
    next_page_url: string | null;
};
