export interface Banner {
    id: number
    title: string
    subtitle: string | null
    image_url: string
    button_text: string | null
    link_url: string | null
    sort_order: number
}
