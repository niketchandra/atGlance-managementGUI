<style>
    .public-page { max-width: 900px; margin: 0 auto; padding: 24px 16px 40px; color: #1f2937; }
    .public-page h1.page-title { font-size: 28px; font-weight: 700; margin-bottom: 20px; color: #111827; }
    .markdown-body { font-size: 15px; line-height: 1.7; }
    .markdown-body h1, .markdown-body h2, .markdown-body h3 { font-weight: 700; margin: 18px 0 8px; color: #111827; }
    .markdown-body h1 { font-size: 22px; }
    .markdown-body h2 { font-size: 19px; }
    .markdown-body h3 { font-size: 17px; }
    .markdown-body p { margin-bottom: 12px; }
    .markdown-body ul { list-style: disc; padding-left: 22px; margin-bottom: 12px; }
    .markdown-body ol { list-style: decimal; padding-left: 22px; margin-bottom: 12px; }
    .markdown-body a { color: #1d4ed8; text-decoration: underline; }
    .markdown-body code { background: #f3f4f6; padding: 1px 4px; border-radius: 4px; font-size: 13px; }
    .public-card { background: #ffffff; border: 1px solid #e5e7eb; border-radius: 10px; padding: 18px; margin-bottom: 16px; }
    .public-card dt { font-size: 12px; text-transform: uppercase; color: #6b7280; font-weight: 600; }
    .public-card dd { margin: 2px 0 12px; font-size: 15px; word-break: break-word; }
    .faq-item { border: 1px solid #e5e7eb; border-radius: 10px; background: #ffffff; margin-bottom: 10px; }
    .faq-item summary { cursor: pointer; padding: 14px 16px; font-weight: 600; }
    .faq-item .markdown-body { padding: 0 16px 12px; }
</style>

<div class="public-page">
    <h1 class="page-title">{{ $pageTitle }}</h1>

    @if(session('success'))
        <div style="margin-bottom: 16px; padding: 12px; border-radius: 8px; background: #ecfdf5; border: 1px solid #a7f3d0; color: #065f46;">{{ session('success') }}</div>
    @endif

    @if($page === 'about')
        <div class="markdown-body">{{ $markdown($profile->about()) }}</div>
    @elseif($page === 'features')
        <div class="features-grid">
            @foreach($profile->features() as $feature)
                <div class="feature-card">
                    <div class="feature-icon"><i class="fas fa-check-circle"></i></div>
                    <div class="feature-title">{{ $feature['title'] }}</div>
                    <div class="feature-desc">{{ $feature['description'] }}</div>
                </div>
            @endforeach
        </div>
    @elseif($page === 'faq')
        @foreach($profile->faq() as $item)
            <details class="faq-item">
                <summary>{{ $item['question'] }}</summary>
                <div class="markdown-body">{{ $markdown($item['answer']) }}</div>
            </details>
        @endforeach
    @elseif($page === 'support')
        @if($support['contact_name'] !== '' || $support['contact_email'] !== '' || $support['contact_phone'] !== '' || $support['hours'] !== '')
            <div class="public-card">
                <dl>
                    @if($support['contact_name'] !== '')
                        <dt>Support contact</dt>
                        <dd>{{ $support['contact_name'] }}</dd>
                    @endif
                    @if($support['contact_email'] !== '')
                        <dt>Email</dt>
                        <dd><a href="mailto:{{ $support['contact_email'] }}" style="color:#1d4ed8;">{{ $support['contact_email'] }}</a></dd>
                    @endif
                    @if($support['contact_phone'] !== '')
                        <dt>Phone</dt>
                        <dd><a href="tel:{{ preg_replace('/[^0-9+]/', '', $support['contact_phone']) }}" style="color:#1d4ed8;">{{ $support['contact_phone'] }}</a></dd>
                    @endif
                    @if($support['hours'] !== '')
                        <dt>Support hours</dt>
                        <dd>{{ $support['hours'] }}</dd>
                    @endif
                </dl>
            </div>
        @endif

        @if($support['request_url'] !== '')
            <div class="public-card">
                <div style="font-weight: 600; margin-bottom: 8px;">Raise a support request</div>
                <a href="{{ $support['request_url'] }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary" style="display: inline-block; text-decoration: none;">
                    <i class="fas fa-external-link-alt"></i> Open request guide
                </a>
            </div>
        @endif

        @if($support['details'] !== '')
            <div class="markdown-body">{{ $markdown($support['details']) }}</div>
        @endif
    @elseif($page === 'contact')
        @if($profile->contactIntro() !== '')
            <div class="markdown-body" style="margin-bottom: 16px;">{{ $markdown($profile->contactIntro()) }}</div>
        @endif

        @if($errors->any())
            <div style="margin-bottom: 16px; padding: 12px; border-radius: 8px; background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;">
                @foreach($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <form class="contact-form" method="POST" action="{{ route('contact') }}" style="margin: 0;">
            @csrf
            <div class="form-group">
                <label for="contact_name"><i class="fas fa-user"></i> Your Name</label>
                <input type="text" id="contact_name" name="name" value="{{ old('name', auth()->user()->name ?? '') }}" maxlength="255" required>
            </div>
            <div class="form-group">
                <label for="contact_email"><i class="fas fa-envelope"></i> Email Address</label>
                <input type="email" id="contact_email" name="email" value="{{ old('email', auth()->user()->email ?? '') }}" maxlength="255" required>
            </div>
            <div class="form-group">
                <label for="contact_subject"><i class="fas fa-heading"></i> Subject</label>
                <input type="text" id="contact_subject" name="subject" value="{{ old('subject') }}" maxlength="255" required>
            </div>
            <div class="form-group">
                <label for="contact_message"><i class="fas fa-comment"></i> Message</label>
                <textarea id="contact_message" name="message" minlength="10" maxlength="5000" required>{{ old('message') }}</textarea>
            </div>
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-paper-plane"></i> Send Message
            </button>
        </form>
    @endif
</div>
