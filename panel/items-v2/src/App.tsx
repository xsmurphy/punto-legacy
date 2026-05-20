import { Button } from '@/components/ui/button';

function App() {
  return (
    <div className="min-h-screen flex flex-col items-center justify-center gap-6 p-8">
      <div className="text-center">
        <h1 className="text-3xl font-semibold tracking-tight">items-v2</h1>
        <p className="text-muted-foreground mt-2">
          Scaffold OK · React + Vite + Tailwind v4 + shadcn
        </p>
      </div>
      <div className="flex gap-3">
        <Button>Primary</Button>
        <Button variant="secondary">Secondary</Button>
        <Button variant="outline">Outline</Button>
        <Button variant="ghost">Ghost</Button>
        <Button variant="destructive">Destructive</Button>
      </div>
      <p className="text-sm text-muted-foreground">
        próximo: cliente API tipado + lista de items
      </p>
    </div>
  );
}

export default App;
