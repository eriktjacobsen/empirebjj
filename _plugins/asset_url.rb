require "digest"

module Jekyll
  module AssetUrl
    def asset_url(path)
      site = @context.registers[:site]
      path = "/#{path.to_s.sub(%r!\A/!, '')}"
      dependencies = [path.delete_prefix("/")]
      if path == "/style.css"
        dependencies += %w[_layouts/style.css _data/template.yml _config.yml
                           _includes/css/bootstrap.min.css _includes/css/agency.css
                           img/header-bg.jpg img/map-image.webp]
      end
      digest = Digest::SHA256.new
      dependencies.each { |file| digest.update(File.binread(File.join(site.source, file))) }
      "#{site.config['baseurl'].to_s.sub(%r!/$!, '')}#{path}?v=#{digest.hexdigest[0, 12]}"
    end
  end
end

Liquid::Template.register_filter(Jekyll::AssetUrl)
