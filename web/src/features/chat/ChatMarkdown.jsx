"use client";

import ReactMarkdown from "react-markdown";
import remarkGfm from "remark-gfm";

/*
 * Sohbet cevabı Markdown'u: başlık, liste, kalın, kod bloğu, tablo (GFM).
 *
 * XSS: react-markdown ham HTML'i işlemez (rehype-raw yok) ve varsayılan
 * urlTransform `javascript:` gibi tehlikeli bağlantıları boşaltır; model
 * çıktısındaki HTML metin olarak görünür, çalışmaz. Bağlantılar yeni
 * sekmede, opener erişimi olmadan açılır.
 *
 * Tailwind'in temel sıfırlaması liste/başlık biçimini kaldırdığı için
 * öğeler burada tek tek biçimleniyor (sitenin koyu tema dili).
 */
const components = {
  h1: (p) => <h3 className="mb-2 mt-3 font-display text-base font-semibold text-white first:mt-0" {...p} />,
  h2: (p) => <h3 className="mb-2 mt-3 font-display text-[15px] font-semibold text-white first:mt-0" {...p} />,
  h3: (p) => <h4 className="mb-1.5 mt-2.5 text-sm font-semibold text-white first:mt-0" {...p} />,
  p: (p) => <p className="mb-2 last:mb-0" {...p} />,
  ul: (p) => <ul className="mb-2 list-disc space-y-1 pl-5 last:mb-0 marker:text-white/40" {...p} />,
  ol: (p) => <ol className="mb-2 list-decimal space-y-1 pl-5 last:mb-0 marker:text-white/40" {...p} />,
  strong: (p) => <strong className="font-semibold text-white" {...p} />,
  a: ({ href, children }) => (
    <a href={href} target="_blank" rel="noopener noreferrer nofollow" className="text-fuchsia-300 underline underline-offset-2 hover:text-fuchsia-200">
      {children}
    </a>
  ),
  blockquote: (p) => <blockquote className="mb-2 border-l-2 border-white/15 pl-3 italic text-white/70" {...p} />,
  code: ({ className, children, ...rest }) =>
    /language-/.test(className || "") ? (
      <code className={className} {...rest}>{children}</code>
    ) : (
      <code className="rounded bg-white/10 px-1 py-0.5 font-mono text-[13px] text-fuchsia-200" {...rest}>{children}</code>
    ),
  pre: (p) => <pre className="mb-2 overflow-x-auto rounded-lg border border-white/10 bg-black/40 p-3 font-mono text-[13px] leading-relaxed text-zinc-200" {...p} />,
  table: (p) => (
    <div className="mb-2 max-w-full overflow-x-auto rounded-lg border border-white/10">
      <table className="w-full border-collapse text-left text-[13px]" {...p} />
    </div>
  ),
  thead: (p) => <thead className="bg-white/[0.06]" {...p} />,
  th: (p) => <th className="border-b border-white/10 px-3 py-1.5 font-semibold text-white" {...p} />,
  td: (p) => <td className="border-b border-white/[0.06] px-3 py-1.5 align-top" {...p} />,
  hr: () => <hr className="my-3 border-white/10" />,
};

// Yazım sırasında son öğenin sonunda yanıp sönen imleç (metinle aynı satırda).
const CURSOR =
  "[&>*:last-child]:after:ml-0.5 [&>*:last-child]:after:inline-block [&>*:last-child]:after:h-[1em] " +
  "[&>*:last-child]:after:w-[2px] [&>*:last-child]:after:translate-y-[2px] [&>*:last-child]:after:rounded-full " +
  "[&>*:last-child]:after:bg-fuchsia-300/80 [&>*:last-child]:after:animate-pulse [&>*:last-child]:after:content-['']";

export default function ChatMarkdown({ children, streaming = false }) {
  return (
    <div className={streaming ? CURSOR : undefined}>
      <ReactMarkdown remarkPlugins={[remarkGfm]} components={components}>
        {children}
      </ReactMarkdown>
    </div>
  );
}
