import { redirect } from "next/navigation"

export default function TeamPage() {
  redirect("/contacts?tab=team")
}
