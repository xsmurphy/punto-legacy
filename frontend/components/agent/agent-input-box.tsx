"use client"

import * as React from "react"
import { Plus, Mic, ArrowUp, FileSpreadsheet, FileText, AlertTriangle, Loader2, X } from "lucide-react"
import { Button } from "@/components/ui/button"
import { Textarea } from "@/components/ui/textarea"
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip"
import type { AttachmentDraft } from "@/lib/agent/attachment-types"

export const AgentInputBox = React.forwardRef<
  HTMLTextAreaElement,
  {
    value: string
    onChange: (v: string) => void
    onSend: () => void
    disabled?: boolean
    placeholder?: string
    maxHeight?: number
    attachments?: AttachmentDraft[]
    onAddFiles?: (files: File[]) => void
    onRemoveAttachment?: (id: string) => void
  }
>(function AgentInputBox(
  {
    value,
    onChange,
    onSend,
    disabled,
    placeholder = "Preguntale al asistente…",
    maxHeight = 160,
    attachments,
    onAddFiles,
    onRemoveAttachment,
  },
  ref,
) {
  const innerRef    = React.useRef<HTMLTextAreaElement>(null)
  const fileInputRef = React.useRef<HTMLInputElement>(null)
  React.useImperativeHandle(ref, () => innerRef.current as HTMLTextAreaElement)

  function autoResize(el: HTMLTextAreaElement) {
    el.style.height = "auto"
    el.style.height = Math.min(el.scrollHeight, maxHeight) + "px"
  }

  React.useEffect(() => {
    if (innerRef.current) autoResize(innerRef.current)
  }, [value])

  const hasPendingAttachments = attachments?.some(
    (a) => a.status === "pending" || a.status === "uploading"
  )

  function handleFileDrop(e: React.DragEvent) {
    e.preventDefault()
    onAddFiles?.(Array.from(e.dataTransfer.files))
  }

  return (
    <div
      className="rounded-3xl border bg-card shadow-sm transition-shadow focus-within:shadow-md"
      onDragOver={(e) => e.preventDefault()}
      onDrop={handleFileDrop}
    >
      {attachments && attachments.length > 0 && (
        <div className="flex flex-wrap gap-1.5 px-3 pt-3">
          {attachments.map((att) => (
            <div
              key={att.id}
              className="flex items-center gap-1.5 rounded-md border bg-card px-2 py-1 text-xs"
            >
              {att.kind === "image" && att.thumbnailDataUrl ? (
                // eslint-disable-next-line @next/next/no-img-element
                <img
                  src={att.thumbnailDataUrl}
                  alt=""
                  className="size-8 rounded object-cover"
                />
              ) : att.kind === "tabular" ? (
                <FileSpreadsheet className="size-4 text-muted-foreground" />
              ) : (
                <FileText className="size-4 text-muted-foreground" />
              )}
              <span className="max-w-[120px] truncate text-foreground">
                {(att.filename ?? att.file.name).length > 20
                  ? (att.filename ?? att.file.name).slice(0, 20) + "…"
                  : (att.filename ?? att.file.name)}
              </span>
              <span className="text-muted-foreground">
                {Math.round(att.file.size / 1024)}KB
              </span>
              {att.status === "uploading" && (
                <Loader2 className="size-3 animate-spin text-muted-foreground" />
              )}
              {att.status === "error" && (
                <span title={att.error}>
                  <AlertTriangle className="size-3 text-destructive" />
                </span>
              )}
              <button
                onClick={() => onRemoveAttachment?.(att.id)}
                className="ml-0.5 text-muted-foreground hover:text-foreground"
                aria-label="Quitar adjunto"
              >
                <X className="size-3" />
              </button>
            </div>
          ))}
        </div>
      )}

      <Textarea
        ref={innerRef}
        value={value}
        onChange={(e) => onChange(e.target.value)}
        onInput={(e) => autoResize(e.currentTarget)}
        onKeyDown={(e) => {
          if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault()
            onSend()
          }
        }}
        placeholder={placeholder}
        disabled={disabled}
        rows={1}
        className="min-h-0 resize-none border-0 bg-transparent shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 px-4 pt-3 pb-1 text-base placeholder:text-muted-foreground/70"
      />

      <input
        ref={fileInputRef}
        type="file"
        multiple
        accept=".xlsx,.xls,.csv,.tsv,.txt,.png,.jpg,.jpeg,.webp,.heic,.pdf,.md"
        className="hidden"
        onChange={(e) => {
          onAddFiles?.(Array.from(e.target.files ?? []))
          e.target.value = ""
        }}
      />

      <div className="flex items-center justify-between px-3 pb-2">
        <Button
          variant="ghost"
          size="icon"
          onClick={() => fileInputRef.current?.click()}
          className="size-9 rounded-full text-muted-foreground/70 hover:text-foreground"
          aria-label="Adjuntar archivo"
        >
          <Plus className="size-4" />
        </Button>
        <div className="flex items-center gap-1">
          <Tooltip>
            <TooltipTrigger asChild>
              <span tabIndex={0}>
                <Button
                  variant="ghost"
                  size="icon"
                  disabled
                  className="size-9 rounded-full text-muted-foreground/70 pointer-events-none"
                >
                  <Mic className="size-4" />
                </Button>
              </span>
            </TooltipTrigger>
            <TooltipContent>Voz (próximamente)</TooltipContent>
          </Tooltip>
          <Button
            onClick={onSend}
            disabled={disabled || (!value.trim() && (!attachments || attachments.length === 0)) || !!hasPendingAttachments}
            size="icon"
            className="size-9 rounded-full bg-foreground text-background hover:bg-foreground/90 disabled:bg-muted disabled:text-muted-foreground"
            aria-label="Enviar"
          >
            <ArrowUp className="size-4" />
          </Button>
        </div>
      </div>
    </div>
  )
})
